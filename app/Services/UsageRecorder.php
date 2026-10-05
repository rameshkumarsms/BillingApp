<?php

namespace App\Services;

use App\Billing\DTO\RecordUsageData;
use Illuminate\Support\Facades\DB;

/**
 * Write path. A single INSERT IGNORE against UNIQUE(customer_id, idempotency_key):
 *  - no read-then-write race (two concurrent retries cannot both insert),
 *  - one round trip, no transaction, no row locks beyond the insert itself,
 *  - the database, not application code, is the source of truth for "have I seen this key".
 */
class UsageRecorder
{
    /** @return bool true if newly recorded, false if it was a duplicate (retry) */
    public function record(RecordUsageData $d): bool
    {
        $affected = DB::table('usage_events')->insertOrIgnore([
            'merchant_id' => $d->merchantId,
            'customer_id' => $d->customerId,
            'idempotency_key' => $d->idempotencyKey,
            'quantity' => $d->quantity,
            'usage_date' => $d->usageDate,
            'created_at' => now(),
        ]);

        return $affected === 1;
    }
}
