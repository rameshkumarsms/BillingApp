<?php

namespace Tests\Feature;

use App\Jobs\AggregateCustomerUsageChunk;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable as D;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

class AggregationTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    public function test_sums_events_per_customer_per_day(): void
    {
        $m = $this->makeMerchant();
        $a = $this->makeCustomer($m, 'Alpha');
        $b = $this->makeCustomer($m, 'Bravo');
        $this->addUsage($a, '2026-01-05', 10);
        $this->addUsage($a, '2026-01-05', 15);
        $this->addUsage($a, '2026-01-06', 7);
        $this->addUsage($b, '2026-01-05', 3);

        $n = app(UsageAggregator::class)->aggregateForCustomers([$a->id, $b->id], D::parse('2026-01-01'), D::parse('2026-01-31'));

        $this->assertSame(3, $n);
        $row = DB::table('usage_daily_aggregates')->where(['customer_id' => $a->id, 'usage_date' => '2026-01-05'])->first();
        $this->assertSame([25, 2], [(int) $row->total_units, (int) $row->event_count]);
        $this->assertSame(3, (int) DB::table('usage_daily_aggregates')->where('customer_id', $b->id)->value('total_units'));
    }

    public function test_rerunning_does_not_double_count_and_picks_up_late_events(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $agg = app(UsageAggregator::class);
        $from = D::parse('2026-01-01');
        $to = D::parse('2026-01-31');

        $this->addUsage($c, '2026-01-05', 10);
        $agg->aggregateForCustomers([$c->id], $from, $to);
        $agg->aggregateForCustomers([$c->id], $from, $to);          // retry
        $this->assertSame(10, (int) DB::table('usage_daily_aggregates')->value('total_units'));

        $this->addUsage($c, '2026-01-05', 5);                       // late event
        $agg->aggregateForCustomers([$c->id], $from, $to);
        $this->assertSame(15, (int) DB::table('usage_daily_aggregates')->value('total_units'));
        $this->assertSame(1, DB::table('usage_daily_aggregates')->count());
    }

    public function test_events_outside_window_are_ignored(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $this->addUsage($c, '2025-12-31', 99);
        $this->addUsage($c, '2026-01-01', 1);
        app(UsageAggregator::class)->aggregateForCustomers([$c->id], D::parse('2026-01-01'), D::parse('2026-01-31'));
        $this->assertSame(1, (int) DB::table('usage_daily_aggregates')->sum('total_units'));
    }

    public function test_queued_job_aggregates_chunk(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $this->addUsage($c, '2026-01-05', 4);
        AggregateCustomerUsageChunk::dispatchSync([$c->id], '2026-01-01', '2026-01-31');
        $this->assertSame(4, (int) DB::table('usage_daily_aggregates')->value('total_units'));
    }

    public function test_command_dispatches_one_job_per_chunk(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['billing.aggregate_chunk' => 2]);
        $m = $this->makeMerchant();
        foreach (['A', 'B', 'C', 'D', 'E'] as $n) {
            $this->makeCustomer($m, $n);
        }
        $this->artisan('usage:aggregate')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(AggregateCustomerUsageChunk::class, 3);   // 2+2+1
    }
}
