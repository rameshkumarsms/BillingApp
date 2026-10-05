<?php

namespace Tests\Feature;

use App\Actions\ChangePlan;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Services\InvoiceGenerator;
use Carbon\CarbonImmutable as D;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

class InvoiceGenerationTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    private function gen(int $subId, string $today)
    {
        return app(InvoiceGenerator::class)->generateAllDue($subId, D::parse($today));
    }

    public function test_prorated_first_invoice_for_mid_cycle_start(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $sub = $this->subscribe($c, $this->makePlan($m), '2026-01-16');
        $this->addUsage($c, '2026-01-20', 600);

        $this->assertSame(1, $this->gen($sub->id, '2026-02-10'));

        $inv = Invoice::query()->with('lines')->first();
        $this->assertSame(55813, (int) $inv->total_minor);                 // see InvoiceCalculatorTest
        $this->assertSame('2026-01-16', $inv->period_start->toDateString());
        $this->assertSame('2026-01-31', $inv->period_end->toDateString());
    }

    public function test_invoice_generation_is_idempotent(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $sub = $this->subscribe($c, $this->makePlan($m), '2026-01-01');
        $this->addUsage($c, '2026-01-02', 1500);

        $this->gen($sub->id, '2026-02-10');
        $again = $this->gen($sub->id, '2026-02-10');

        $this->assertSame(0, $again);
        $this->assertSame(1, Invoice::query()->where('subscription_id', $sub->id)->where('period_start', '2026-01-01')->count());
        $this->assertSame(125000, (int) Invoice::query()->first()->total_minor);
    }

    public function test_no_invoice_until_cycle_and_grace_have_passed(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $sub = $this->subscribe($c, $this->makePlan($m), '2026-01-01');
        $this->assertSame(0, $this->gen($sub->id, '2026-01-31'));
        $this->assertSame(0, $this->gen($sub->id, '2026-02-01'));   // grace day
        $this->assertSame(1, $this->gen($sub->id, '2026-02-02'));
    }

    public function test_catches_up_multiple_missed_cycles(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $sub = $this->subscribe($c, $this->makePlan($m), '2026-01-01');
        $this->assertSame(3, $this->gen($sub->id, '2026-04-05'));   // Jan, Feb, Mar
        $this->assertSame(3, Invoice::query()->count());
    }

    public function test_events_do_not_leak_across_cycles(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $sub = $this->subscribe($c, $this->makePlan($m), '2026-01-01');
        $this->addUsage($c, '2026-01-31', 1100);   // Jan: 100 over => 5,000
        $this->addUsage($c, '2026-02-01', 5000);   // Feb
        $this->gen($sub->id, '2026-03-05');
        $jan = Invoice::query()->where('period_start', '2026-01-01')->first();
        $this->assertSame(105000, (int) $jan->total_minor);
    }

    public function test_mid_cycle_upgrade_bills_each_segment_on_its_own_plan(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $a = $this->makePlan($m, ['name' => 'A', 'base_price_minor' => 100000, 'included_units' => 1000, 'overage_rate_micros' => 500000]);
        $b = $this->makePlan($m, ['name' => 'B', 'base_price_minor' => 300000, 'included_units' => 5000, 'overage_rate_micros' => 200000]);
        $sub = $this->subscribe($c, $a, '2026-01-01');

        $this->addUsage($c, '2026-01-05', 400);      // on A
        app(ChangePlan::class)->execute($sub, $b, D::parse('2026-01-11'));
        $this->addUsage($c, '2026-01-20', 4000);     // on B

        $this->gen($sub->id, '2026-02-10');

        $inv = Invoice::query()->with('lines')->first();
        $this->assertSame(251594, (int) $inv->total_minor);                 // see InvoiceCalculatorTest
        $over = $inv->lines->where('type', 'overage')->values();
        $this->assertSame([$a->id, 500000, 77], [$over[0]->plan_id, (int) $over[0]->unit_rate_micros, (int) $over[0]->quantity]);
        $this->assertSame([$b->id, 200000, 613], [$over[1]->plan_id, (int) $over[1]->unit_rate_micros, (int) $over[1]->quantity]);
    }

    public function test_usage_on_effective_date_belongs_to_new_plan(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $a = $this->makePlan($m, ['name' => 'A']);
        $b = $this->makePlan($m, ['name' => 'B', 'overage_rate_micros' => 200000, 'included_units' => 0, 'base_price_minor' => 0]);
        $sub = $this->subscribe($c, $a, '2026-01-01');
        app(ChangePlan::class)->execute($sub, $b, D::parse('2026-01-11'));
        $this->addUsage($c, '2026-01-10', 10);   // last day on A  (within A's allowance)
        $this->addUsage($c, '2026-01-11', 10);   // first day on B (B allowance 0 => 10 * 20p)

        $this->gen($sub->id, '2026-02-10');
        $lines = Invoice::query()->first()->lines->where('type', 'overage');
        $this->assertCount(1, $lines);
        $this->assertSame($b->id, $lines->first()->plan_id);
        $this->assertSame(200, (int) $lines->first()->amount_minor);
    }

    public function test_cannot_change_plan_for_invoiced_period_or_across_cycle_types(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $a = $this->makePlan($m);
        $b = $this->makePlan($m, ['name' => 'B']);
        $yearly = $this->makePlan($m, ['name' => 'Y', 'billing_cycle' => 'yearly']);
        $sub = $this->subscribe($c, $a, '2026-01-01');
        $this->gen($sub->id, '2026-02-10');

        try {
            app(ChangePlan::class)->execute($sub, $b, D::parse('2026-01-20'));
            $this->fail('Expected BillingException');
        } catch (BillingException $e) {
            $this->assertStringContainsString('already been invoiced', $e->getMessage());
        }
        $this->expectException(BillingException::class);
        app(ChangePlan::class)->execute($sub, $yearly, D::parse('2026-03-01'));
    }

    public function test_same_day_change_replaces_segment_without_zero_length_gap(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $a = $this->makePlan($m);
        $b = $this->makePlan($m, ['name' => 'B']);
        $sub = $this->subscribe($c, $a, '2026-01-01');
        app(ChangePlan::class)->execute($sub, $b, D::parse('2026-01-01'));
        $this->assertSame(1, $sub->segments()->count());
        $this->assertSame($b->id, $sub->segments()->first()->plan_id);
    }
}
