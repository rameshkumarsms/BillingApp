<?php

namespace App\Services;

use App\Billing\BillingCycle;
use App\Billing\DTO\BillingPeriod;
use App\Models\Invoice;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/** Decides which period (if any) of a subscription is next due to be invoiced. */
class BillingPeriodPlanner
{
    public function __construct(private PlanCache $plans) {}

    public function cycleTypeFor(Subscription $sub): string
    {
        // Plan changes are restricted to the same billing cycle (A4), so the first segment is representative.
        return $this->plans->terms($sub->segments->first()->plan_id)->billingCycle;
    }

    public function nextDuePeriod(Subscription $sub, CarbonImmutable $today): ?BillingPeriod
    {
        $lastEnd = Invoice::query()->where('subscription_id', $sub->id)->max('period_end');
        $start = $lastEnd ? CarbonImmutable::parse($lastEnd)->addDay() : $sub->started_on;

        [$cycleStart, $cycleEnd] = BillingCycle::bounds($this->cycleTypeFor($sub), $start);

        // Only invoice once the cycle (plus late-event grace) is fully in the past.
        if (! $today->gt($cycleEnd->addDays(config('billing.invoice_grace_days')))) {
            return null;
        }

        return new BillingPeriod($start, $cycleEnd, BillingCycle::days($cycleStart, $cycleEnd));
    }
}
