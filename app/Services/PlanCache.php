<?php

namespace App\Services;

use App\Billing\DTO\PlanTerms;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-aside for plan pricing. Stores plain arrays (not Eloquent models) so entries are
 * small and survive model refactors.
 *
 * Invalidation: PlanObserver calls forget() on every Eloquent save/delete (event-driven, exact).
 * TTL (billing.plan_cache_ttl) is the backstop for writes that bypass Eloquent events
 * (raw SQL, query-builder mass updates) so stale pricing can never live longer than the TTL.
 */
class PlanCache
{
    public function terms(int $planId): PlanTerms
    {
        $data = Cache::remember(self::termsKey($planId), config('billing.plan_cache_ttl'), function () use ($planId) {
            $p = Plan::query()->findOrFail($planId);

            return (new PlanTerms(
                $p->id, $p->name, $p->billing_cycle, (int) $p->base_price_minor,
                (int) $p->included_units, (int) $p->overage_rate_micros, $p->currency,
            ))->toArray();
        });

        return PlanTerms::fromArray($data);
    }

    /** @return array<int, array<string, mixed>> active plans of a merchant */
    public function forMerchant(int $merchantId): array
    {
        return Cache::remember(self::merchantKey($merchantId), config('billing.plan_cache_ttl'), fn () => Plan::query()
            ->where('merchant_id', $merchantId)->where('is_active', true)->orderBy('id')
            ->get(['id', 'name', 'billing_cycle', 'base_price_minor', 'included_units', 'overage_rate_micros', 'currency'])
            ->toArray());
    }

    public function forget(int $planId, int $merchantId): void
    {
        Cache::forget(self::termsKey($planId));
        Cache::forget(self::merchantKey($merchantId));
    }

    private static function termsKey(int $id): string
    {
        return "plan:terms:{$id}";
    }

    private static function merchantKey(int $id): string
    {
        return "merchant:{$id}:plans";
    }
}
