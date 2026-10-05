<?php

namespace App\Services;

use App\Billing\DTO\BillingSegment;
use App\Billing\InvoiceCalculator;
use App\Models\Invoice;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates one invoice: pick period -> refresh rollups -> split by plan segment -> calculate -> persist.
 *
 * Safe to run twice / concurrently: the subscription row is locked for the duration, the "next due
 * period" is recomputed inside the lock, and UNIQUE(subscription_id, period_start) is the final backstop.
 */
class InvoiceGenerator
{
    public function __construct(
        private BillingPeriodPlanner $planner,
        private SegmentResolver $resolver,
        private UsageAggregator $aggregator,
        private UsageRepository $usage,
        private PlanCache $plans,
        private InvoiceCalculator $calculator,
    ) {}

    /** Catch-up: bills every completed, un-invoiced period. @return int invoices created */
    public function generateAllDue(int $subscriptionId, CarbonImmutable $today): int
    {
        $n = 0;
        while ($this->generateNext($subscriptionId, $today) !== null) {
            $n++;
        }

        return $n;
    }

    public function generateNext(int $subscriptionId, CarbonImmutable $today): ?Invoice
    {
        return DB::transaction(function () use ($subscriptionId, $today) {
            $sub = Subscription::query()->lockForUpdate()->with('segments')->find($subscriptionId);
            if (! $sub || $sub->status !== 'active' || $sub->segments->isEmpty()) {
                return null;
            }

            $period = $this->planner->nextDuePeriod($sub, $today);
            if (! $period) {
                return null;
            }

            // Re-aggregate just this customer/period so the invoice never depends on the nightly job having run.
            $this->aggregator->aggregateForCustomers([$sub->customer_id], $period->start, $period->end);
            $rows = $this->usage->dailyRows([$sub->customer_id], $period->start, $period->end);

            $segments = [];
            foreach ($this->resolver->resolve($sub, $period->start, $period->end) as $s) {
                $segments[] = new BillingSegment(
                    $this->plans->terms($s['plan_id']), $s['start'], $s['end'],
                    $this->usage->sum($rows, $s['start'], $s['end']),
                );
            }

            $result = $this->calculator->calculate($segments, $period->cycleDays);

            $invoice = Invoice::query()->create([
                'merchant_id' => $sub->merchant_id,
                'customer_id' => $sub->customer_id,
                'subscription_id' => $sub->id,
                'period_start' => $period->start->toDateString(),
                'period_end' => $period->end->toDateString(),
                'currency' => $segments[0]->plan->currency,
                'total_minor' => $result->totalMinor(),
                'status' => 'issued',
                'generated_at' => now(),
            ]);

            $invoice->lines()->createMany(array_map(fn ($l) => [
                'type' => $l->type,
                'plan_id' => $l->planId,
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit_rate_micros' => $l->unitRateMicros,
                'amount_minor' => $l->amountMinor,
                'segment_start' => $l->segmentStart->toDateString(),
                'segment_end' => $l->segmentEnd->toDateString(),
            ], $result->lines));

            return $invoice;
        });
    }
}
