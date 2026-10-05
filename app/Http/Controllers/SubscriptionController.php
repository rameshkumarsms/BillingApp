<?php

namespace App\Http\Controllers;

use App\Actions\ChangePlan;
use App\Actions\SubscribeCustomer;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function store(Request $request, SubscribeCustomer $action)
    {
        $m = $request->attributes->get('merchant_id');
        $data = $request->validate([
            'customer_id' => ['required', 'integer'], 'plan_id' => ['required', 'integer'],
            'started_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $sub = $action->execute(
            Customer::query()->where('merchant_id', $m)->findOrFail($data['customer_id']),
            Plan::query()->where('merchant_id', $m)->findOrFail($data['plan_id']),
            CarbonImmutable::parse($data['started_on'] ?? 'today'),
        );

        return response()->json(['data' => $sub->load('segments')], 201);
    }

    public function changePlan(Request $request, int $subscription, ChangePlan $action)
    {
        $m = $request->attributes->get('merchant_id');
        $data = $request->validate(['plan_id' => ['required', 'integer'], 'effective_on' => ['nullable', 'date_format:Y-m-d']]);
        $sub = $action->execute(
            Subscription::query()->where('merchant_id', $m)->findOrFail($subscription),
            Plan::query()->where('merchant_id', $m)->findOrFail($data['plan_id']),
            CarbonImmutable::parse($data['effective_on'] ?? 'today'),
        );

        return response()->json(['data' => $sub]);
    }
}
