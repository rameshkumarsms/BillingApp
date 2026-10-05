<?php

namespace App\Actions;

use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\DashboardService;
use App\Services\PlanCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Upgrade/downgrade. The change is effective at START of `$effectiveOn`: usage dated before it
 * is billed on the old plan, usage dated on/after it on the new plan. Implemented by closing the
 * open segment (ends_on = effectiveOn - 1) and appending a new one; history is never rewritten.
 */
class ChangePlan
{
    public function __construct(private PlanCache $plans, private DashboardService $dashboard) {}

    public function execute(Subscription $subscription, Plan $newPlan, CarbonImmutable $effectiveOn): Subscription
    {
        DB::transaction(function () use ($subscription, $newPlan, $effectiveOn) {
            $sub = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $current = $sub->segments()->whereNull('ends_on')->latest('starts_on')->first();

            if ($sub->status !== 'active' || ! $current) {
                throw new BillingException('Subscription is not active.');
            }
            if ($newPlan->merchant_id !== $sub->merchant_id || ! $newPlan->is_active) {
                throw new BillingException('Target plan is not available to this merchant.');
            }
            if ($current->plan_id === $newPlan->id) {
                throw new BillingException('Subscription is already on this plan.');
            }
            if ($this->plans->terms($current->plan_id)->billingCycle !== $newPlan->billing_cycle) {
                throw new BillingException('Plan changes must keep the same billing cycle.');
            }
            if ($effectiveOn->lt($current->starts_on)) {
                throw new BillingException('Effective date is before the current plan started.');
            }
            $lastInvoiced = Invoice::query()->where('subscription_id', $sub->id)->max('period_end');
            if ($lastInvoiced && $effectiveOn->lte(CarbonImmutable::parse($lastInvoiced))) {
                throw new BillingException('Cannot change the plan for a period that has already been invoiced.');
            }

            if ($effectiveOn->equalTo($current->starts_on)) {
                $current->update(['plan_id' => $newPlan->id]);       // same-day correction: no zero-length segment
            } else {
                $current->update(['ends_on' => $effectiveOn->subDay()->toDateString()]);
                $sub->segments()->create(['plan_id' => $newPlan->id, 'starts_on' => $effectiveOn->toDateString(), 'ends_on' => null]);
            }
        });

        $this->dashboard->forget($subscription->merchant_id);

        return $subscription->fresh('segments');
    }
}
