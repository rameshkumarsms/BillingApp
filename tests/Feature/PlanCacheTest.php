<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\PlanCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

class PlanCacheTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    public function test_second_lookup_is_served_from_cache(): void
    {
        $p = $this->makePlan($this->makeMerchant());
        $cache = app(PlanCache::class);
        $cache->terms($p->id);

        DB::enableQueryLog();
        $cache->terms($p->id);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_eloquent_update_invalidates_cache(): void
    {
        $p = $this->makePlan($this->makeMerchant());
        $cache = app(PlanCache::class);
        $this->assertSame(1000, $cache->terms($p->id)->includedUnits);

        $p->update(['included_units' => 2000]);
        $this->assertSame(2000, $cache->terms($p->id)->includedUnits);
    }

    public function test_merchant_plan_list_invalidated_on_create(): void
    {
        $m = $this->makeMerchant();
        $this->makePlan($m);
        $cache = app(PlanCache::class);
        $this->assertCount(1, $cache->forMerchant($m->id));
        $this->makePlan($m, ['name' => 'Scale']);
        $this->assertCount(2, $cache->forMerchant($m->id));
    }

    public function test_pricing_of_plan_with_subscribers_is_immutable_via_api(): void
    {
        $m = $this->makeMerchant();
        $p = $this->makePlan($m);
        $this->subscribe($this->makeCustomer($m), $p, '2026-01-01');

        $this->putJson("/api/plans/{$p->id}", ['base_price_minor' => 1], ['X-Api-Key' => 'test-key'])->assertStatus(422);
        $this->putJson("/api/plans/{$p->id}", ['name' => 'Renamed'], ['X-Api-Key' => 'test-key'])->assertOk();
        $this->assertSame('Renamed', Plan::find($p->id)->name);
    }
}
