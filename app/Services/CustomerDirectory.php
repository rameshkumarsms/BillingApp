<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

/**
 * customer_id -> merchant_id, cached for 1h. This lets POST /usage verify tenancy without a DB
 * read per request. A customer never changes merchant, so this mapping is immutable (no invalidation needed).
 */
class CustomerDirectory
{
    public function merchantIdFor(int $customerId): ?int
    {
        $id = Cache::remember("customer:{$customerId}:merchant", 3600, fn () => Customer::query()->whereKey($customerId)->value('merchant_id'));

        return $id === null ? null : (int) $id;
    }
}
