<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rolls raw events up into usage_daily_aggregates.
 *
 * Idempotent by construction: it RECOMPUTES the totals for the (customer, day) window and upserts
 * them (overwrite, never increment). Re-running a chunk, retrying a failed job, or re-aggregating
 * to absorb late events can never double count.
 */
class UsageAggregator
{
    /** @param int[] $customerIds @return int number of (customer, day) rows written */
    public function aggregateForCustomers(array $customerIds, CarbonInterface $from, CarbonInterface $to): int
    {
        if ($customerIds === []) {
            return 0;
        }

        // Uses (customer_id, usage_date) index; grouping happens in the DB, only rollups come back.
        $rows = DB::table('usage_events')
            ->whereIn('customer_id', $customerIds)
            ->whereBetween('usage_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('customer_id', 'merchant_id', 'usage_date')
            ->selectRaw('customer_id, merchant_id, usage_date, SUM(quantity) AS total_units, COUNT(*) AS event_count')
            ->get();

        $now = now();
        $written = 0;
        foreach ($rows->chunk(config('billing.upsert_batch')) as $chunk) {
            $payload = $chunk->map(fn ($r) => [
                'customer_id' => (int) $r->customer_id,
                'usage_date' => $r->usage_date,
                'merchant_id' => (int) $r->merchant_id,
                'total_units' => (int) $r->total_units,
                'event_count' => (int) $r->event_count,
                'computed_at' => $now,
            ])->values()->all();

            DB::table('usage_daily_aggregates')->upsert(
                $payload, ['customer_id', 'usage_date'], ['merchant_id', 'total_units', 'event_count', 'computed_at'],
            );
            $written += count($payload);
        }

        return $written;
    }
}
