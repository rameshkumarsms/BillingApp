<?php

namespace Tests\Unit;

use App\Billing\BillingCycle;
use App\Billing\DTO\BillingSegment;
use App\Billing\DTO\InvoiceLineData;
use App\Billing\DTO\PlanTerms;
use App\Billing\InvoiceCalculator;
use App\Billing\Money;
use Carbon\CarbonImmutable as D;
use PHPUnit\Framework\TestCase;

/**
 * Money is paise. Rates are micros: 500_000 micros = ₹0.50 = 50 paise/unit.
 * Expected values below are worked out by hand in the comments.
 */
class InvoiceCalculatorTest extends TestCase
{
    private function plan(int $id = 1, int $base = 100000, int $included = 1000, int $rate = 500000, string $cycle = 'monthly'): PlanTerms
    {
        return new PlanTerms($id, "Plan{$id}", $cycle, $base, $included, $rate);
    }

    private function seg(PlanTerms $p, string $s, string $e, int $usage): BillingSegment
    {
        return new BillingSegment($p, D::parse($s), D::parse($e), $usage);
    }

    private function calc(array $segments, int $cycleDays)
    {
        return (new InvoiceCalculator())->calculate($segments, $cycleDays);
    }

    public function test_full_cycle_with_overage(): void
    {
        // 1500 used, 1000 included => 500 over * 50p = 25,000 ; base 100,000
        $r = $this->calc([$this->seg($this->plan(), '2026-01-01', '2026-01-31', 1500)], 31);
        $this->assertSame(125000, $r->totalMinor());
        $this->assertSame(25000, $r->overageMinor());
        $this->assertCount(2, $r->lines);
    }

    public function test_usage_exactly_at_allowance_has_no_overage_line(): void
    {
        $r = $this->calc([$this->seg($this->plan(), '2026-01-01', '2026-01-31', 1000)], 31);
        $this->assertSame(100000, $r->totalMinor());
        $this->assertCount(1, $r->lines);
    }

    public function test_one_unit_over_allowance(): void
    {
        $r = $this->calc([$this->seg($this->plan(), '2026-01-01', '2026-01-31', 1001)], 31);
        $this->assertSame(100050, $r->totalMinor());
    }

    public function test_zero_usage_bills_base_only(): void
    {
        $r = $this->calc([$this->seg($this->plan(), '2026-01-01', '2026-01-31', 0)], 31);
        $this->assertSame(100000, $r->totalMinor());
        $this->assertSame(0, $r->overageMinor());
    }

    public function test_mid_cycle_start_prorates_base_and_allowance(): void
    {
        // Jan 16-31 = 16/31 days. base = round(100000*16/31)=51,613 ; allowance = round(1000*16/31)=516
        // usage 600 => 84 over * 50p = 4,200 ; total 55,813
        $r = $this->calc([$this->seg($this->plan(), '2026-01-16', '2026-01-31', 600)], 31);
        $this->assertSame(51613, $r->lines[0]->amountMinor);
        $this->assertSame(84, $r->lines[1]->quantity);
        $this->assertSame(55813, $r->totalMinor());
    }

    public function test_usage_within_prorated_allowance_has_no_overage(): void
    {
        // 516 allowance (see above); 516 used => none
        $r = $this->calc([$this->seg($this->plan(), '2026-01-16', '2026-01-31', 516)], 31);
        $this->assertSame(51613, $r->totalMinor());
    }

    public function test_february_non_leap_and_leap_denominators(): void
    {
        // Feb 15-28 2026: 14/28 => exactly half
        $r = $this->calc([$this->seg($this->plan(), '2026-02-15', '2026-02-28', 0)], BillingCycle::days(D::parse('2026-02-01'), D::parse('2026-02-28')));
        $this->assertSame(50000, $r->totalMinor());
        // Leap Feb 2028 has 29 days
        $this->assertSame(29, BillingCycle::days(D::parse('2028-02-01'), D::parse('2028-02-29')));
        $this->assertSame(28, BillingCycle::days(D::parse('2026-02-01'), D::parse('2026-02-28')));
    }

    public function test_yearly_cycle_full_year(): void
    {
        $p = $this->plan(1, 1200000, 12000, 500000, 'yearly');
        $r = $this->calc([$this->seg($p, '2026-01-01', '2026-12-31', 12000)], 365);
        $this->assertSame(1200000, $r->totalMinor());
    }

    public function test_plan_change_bills_each_segment_at_its_own_rate(): void
    {
        // Jan (31d). Plan A: Jan 1-10 (10d), Plan B: Jan 11-31 (21d).
        $a = $this->plan(1, 100000, 1000, 500000);   // 50p/unit over
        $b = $this->plan(2, 300000, 5000, 200000);   // 20p/unit over
        // A: base round(100000*10/31)=32,258 ; allowance round(1000*10/31)=323 ; usage 400 => 77*50p = 3,850
        // B: base round(300000*21/31)=203,226 ; allowance round(5000*21/31)=3,387 ; usage 4000 => 613*20p = 12,260
        $r = $this->calc([
            $this->seg($a, '2026-01-01', '2026-01-10', 400),
            $this->seg($b, '2026-01-11', '2026-01-31', 4000),
        ], 31);

        $this->assertSame(32258 + 3850 + 203226 + 12260, $r->totalMinor());
        $this->assertSame(251594, $r->totalMinor());
        // Old-plan usage is billed at the OLD rate, new-plan usage at the NEW rate.
        $overage = array_values(array_filter($r->lines, fn ($l) => $l->type === InvoiceLineData::OVERAGE));
        $this->assertSame([1, 500000, 77], [$overage[0]->planId, $overage[0]->unitRateMicros, $overage[0]->quantity]);
        $this->assertSame([2, 200000, 613], [$overage[1]->planId, $overage[1]->unitRateMicros, $overage[1]->quantity]);
    }

    public function test_plan_change_allowance_does_not_roll_over_between_segments(): void
    {
        // A uses 0 of its allowance; B overuses. A's leftover must NOT offset B's overage.
        $a = $this->plan(1, 100000, 1000, 500000);
        $b = $this->plan(2, 100000, 1000, 500000);
        $r = $this->calc([
            $this->seg($a, '2026-01-01', '2026-01-15', 0),
            $this->seg($b, '2026-01-16', '2026-01-31', 1000),   // B allowance = 516 => 484 over
        ], 31);
        $this->assertSame(484, $r->lines[array_key_last($r->lines)]->quantity);
    }

    public function test_one_day_segment(): void
    {
        $r = $this->calc([$this->seg($this->plan(), '2026-01-31', '2026-01-31', 0)], 31);
        $this->assertSame(Money::mulDivRound(100000, 1, 31), $r->totalMinor());   // 3,226
    }

    public function test_fractional_rate_is_exact_with_micros(): void
    {
        // ₹0.15/unit = 150,000 micros = 15p ; 3 over => 45p
        $p = $this->plan(1, 0, 0, 150000);
        $r = $this->calc([$this->seg($p, '2026-01-01', '2026-01-31', 3)], 31);
        $this->assertSame(45, $r->totalMinor());
    }

    public function test_rounding_is_half_up(): void
    {
        $this->assertSame(1, Money::mulDivRound(1, 1, 2));
        $this->assertSame(0, Money::mulDivRound(1, 1, 3));
        $this->assertSame(2, Money::mulDivRound(3, 1, 2));
    }

    public function test_segment_longer_than_cycle_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc([$this->seg($this->plan(), '2026-01-01', '2026-01-31', 0)], 30);
    }

    public function test_empty_segments_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc([], 31);
    }
}
