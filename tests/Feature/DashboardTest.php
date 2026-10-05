<?php

namespace Tests\Feature;

use App\Services\DashboardService;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable as D;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    private const TODAY = '2026-03-10';   // 10 days elapsed => compares Mar 1-10 vs Feb 1-10

    private function rollup(array $customers): void
    {
        app(UsageAggregator::class)->aggregateForCustomers(array_map(fn ($c) => $c->id, $customers), D::parse('2026-01-01'), D::parse('2026-03-31'));
    }

    public function test_top_five_ranked_by_month_to_date_usage_with_allowance_percent(): void
    {
        $m = $this->makeMerchant();
        $plan = $this->makePlan($m, ['included_units' => 1000]);
        $cs = [];
        foreach ([100, 900, 300, 700, 500, 200, 800] as $i => $qty) {
            $c = $this->makeCustomer($m, "C{$i}");
            $this->subscribe($c, $plan, '2026-01-01');
            $this->addUsage($c, '2026-03-05', $qty);
            $cs[] = $c;
        }
        $this->rollup($cs);

        $top = app(DashboardService::class)->build($m->id, D::parse(self::TODAY))['top_customers'];

        $this->assertCount(5, $top);
        $this->assertSame([900, 800, 700, 500, 300], array_column($top, 'usage'));
        $this->assertSame(90.0, $top[0]['percent_of_allowance']);
    }

    public function test_churn_risk_flags_only_drops_greater_than_fifty_percent(): void
    {
        $m = $this->makeMerchant();
        $plan = $this->makePlan($m);
        $drop = $this->makeCustomer($m, 'Dropper');    // 1000 -> 400  (-60%)  => flagged
        $edge = $this->makeCustomer($m, 'Edge');       // 1000 -> 500  (-50%)  => NOT flagged (needs >50%)
        $flat = $this->makeCustomer($m, 'Flat');       // 1000 -> 1000
        $tiny = $this->makeCustomer($m, 'Tiny');       // 50 -> 0 (below noise floor)
        $gone = $this->makeCustomer($m, 'Gone');       // 1000 -> 0   (-100%)  => flagged
        foreach ([$drop, $edge, $flat, $tiny, $gone] as $c) {
            $this->subscribe($c, $plan, '2026-01-01');
        }
        $this->addUsage($drop, '2026-02-05', 1000); $this->addUsage($drop, '2026-03-05', 400);
        $this->addUsage($edge, '2026-02-05', 1000); $this->addUsage($edge, '2026-03-05', 500);
        $this->addUsage($flat, '2026-02-05', 1000); $this->addUsage($flat, '2026-03-05', 1000);
        $this->addUsage($tiny, '2026-02-05', 50);
        $this->addUsage($gone, '2026-02-05', 1000);
        $this->addUsage($flat, '2026-02-20', 9999);    // Feb 11+ is outside the like-for-like window: must not matter
        $this->rollup([$drop, $edge, $flat, $tiny, $gone]);

        $risk = app(DashboardService::class)->build($m->id, D::parse(self::TODAY))['churn_risk'];

        $this->assertEqualsCanonicalizing(['Dropper', 'Gone'], array_column($risk, 'customer'));
        $byName = array_column($risk, null, 'customer');
        $this->assertSame(60, $byName['Dropper']['drop_percent']);
        $this->assertSame(100, $byName['Gone']['drop_percent']);
    }

    public function test_projected_overage_extrapolates_run_rate_through_the_calculator(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $this->subscribe($c, $this->makePlan($m), '2026-03-01');   // 1000 incl, 50p/unit over
        $this->addUsage($c, '2026-03-04', 500);                     // 500 in 10 days => 50/day
        $this->rollup([$c]);

        // remaining 21 days => +1050 => 1550 projected ; 550 over * 50p = 27,500
        $s = app(DashboardService::class)->build($m->id, D::parse(self::TODAY))['summary'];
        $this->assertSame(27500, $s['projected_overage_revenue_minor']);
        $this->assertSame(500, $s['current_cycle_usage']);
        $this->assertSame(1000, $s['current_cycle_allowance']);
    }

    public function test_projection_is_zero_when_on_track_within_allowance(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $this->subscribe($c, $this->makePlan($m), '2026-03-01');
        $this->addUsage($c, '2026-03-04', 100);                     // 10/day => 310 projected
        $this->rollup([$c]);
        $s = app(DashboardService::class)->build($m->id, D::parse(self::TODAY))['summary'];
        $this->assertSame(0, $s['projected_overage_revenue_minor']);
    }

    public function test_daily_trend_is_zero_filled_30_days(): void
    {
        $m = $this->makeMerchant();
        $trend = app(DashboardService::class)->build($m->id, D::parse(self::TODAY))['daily_trend'];
        $this->assertCount(30, $trend);
        $this->assertSame(self::TODAY, end($trend)['date']);
    }

    public function test_endpoint_is_tenant_scoped(): void
    {
        $a = $this->makeMerchant('key-a', 'A');
        $b = $this->makeMerchant('key-b', 'B');
        $this->getJson("/api/merchants/{$a->id}/dashboard", ['X-Api-Key' => 'key-a'])->assertOk()->assertJsonStructure(['data' => ['summary', 'top_customers', 'churn_risk', 'daily_trend']]);
        $this->getJson("/api/merchants/{$b->id}/dashboard", ['X-Api-Key' => 'key-a'])->assertForbidden();
        $this->getJson("/api/merchants/{$a->id}/dashboard")->assertUnauthorized();
    }
}
