<?php

namespace App\Http\Controllers;

use App\Billing\DTO\RecordUsageData;
use App\Http\Requests\StoreUsageRequest;
use App\Services\CustomerDirectory;
use App\Services\UsageRecorder;

class UsageController extends Controller
{
    public function store(StoreUsageRequest $request, UsageRecorder $recorder, CustomerDirectory $customers)
    {
        $merchantId = $request->attributes->get('merchant_id');
        $d = $request->validated();

        if ($customers->merchantIdFor($d['customer_id']) !== $merchantId) {
            abort(404, 'Customer not found.');
        }

        $created = $recorder->record(new RecordUsageData(
            $merchantId, $d['customer_id'], $d['quantity'], $d['usage_date'], $d['idempotency_key'],
        ));

        // 201 first time; 200 for a replay of the same key (client can safely treat both as success).
        return response()->json(['data' => ['idempotency_key' => $d['idempotency_key'], 'duplicate' => ! $created]], $created ? 201 : 200);
    }
}
