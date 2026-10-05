<?php

namespace Database\Seeders;

use App\Actions\SubscribeCustomer;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $today = CarbonImmutable::today();
        $m = Merchant::query()->create(['name' => 'Acme Corp', 'api_key_hash' => hash('sha256', 'demo-api-key')]);

        $growth = Plan::query()->create(['merchant_id' => $m->id, 'name' => 'Growth', 'billing_cycle' => 'monthly',
            'base_price_minor' => 499900, 'included_units' => 25000, 'overage_rate_micros' => 150000]);   // ₹4,999 / 25k / ₹0.15
        Plan::query()->create(['merchant_id' => $m->id, 'name' => 'Scale', 'billing_cycle' => 'monthly',
            'base_price_minor' => 1499900, 'included_units' => 100000, 'overage_rate_micros' => 80000]);

        $subscribe = app(SubscribeCustomer::class);
        $ids = [];
        foreach (['Beta Retail Pvt Ltd', 'Craft Foods Co.', 'Delta Mart', 'Nova Traders', 'QuickMart', 'Orbit Labs', 'Pixel Pay'] as $i => $name) {
            $c = Customer::query()->create(['merchant_id' => $m->id, 'name' => $name, 'email' => 'ops'.$i.'@example.com']);
            $subscribe->execute($c, $growth, $today->subMonths(2)->startOfMonth());
            $ids[] = $c->id;
        }

        // 70 days of usage. Nova Traders / QuickMart fall off a cliff this month => churn risk demo.
        $rows = [];
        foreach ($ids as $i => $cid) {
            for ($d = 0; $d < 70; $d++) {
                $date = $today->subDays($d);
                $base = 300 + $i * 90;
                $qty = ($i >= 3 && $i <= 4 && $date->gte($today->startOfMonth())) ? intdiv($base, 4) : $base + random_int(0, 80);
                $rows[] = ['merchant_id' => $m->id, 'customer_id' => $cid, 'idempotency_key' => "seed-{$cid}-{$date->toDateString()}",
                    'quantity' => $qty, 'usage_date' => $date->toDateString(), 'created_at' => now()];
            }
        }
        DB::table('usage_events')->insert($rows);
        app(UsageAggregator::class)->aggregateForCustomers($ids, $today->subDays(70), $today);

        $this->command?->info('Demo merchant id='.$m->id.'  X-Api-Key: demo-api-key');
    }
}
