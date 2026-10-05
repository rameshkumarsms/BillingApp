<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingException;
use App\Models\Plan;
use App\Services\PlanCache;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:100'],
            'billing_cycle' => [$req, 'in:monthly,yearly'],
            'base_price_minor' => [$req, 'integer', 'min:0'],
            'included_units' => [$req, 'integer', 'min:0'],
            'overage_rate_micros' => [$req, 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function index(Request $request, PlanCache $cache)
    {
        return response()->json(['data' => $cache->forMerchant($request->attributes->get('merchant_id'))]);
    }

    public function store(Request $request)
    {
        $plan = Plan::query()->create($request->validate($this->rules()) + ['merchant_id' => $request->attributes->get('merchant_id')]);

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, int $plan)
    {
        $model = Plan::query()->where('merchant_id', $request->attributes->get('merchant_id'))->findOrFail($plan);
        $data = $request->validate($this->rules(true));

        // Prices of a plan that already has subscribers are immutable: editing them would silently
        // re-price usage that was already metered. Create a new plan and use change-plan instead.
        $pricing = array_intersect_key($data, array_flip(['billing_cycle', 'base_price_minor', 'included_units', 'overage_rate_micros']));
        $changed = array_filter($pricing, fn ($v, $k) => (string) $model->{$k} !== (string) $v, ARRAY_FILTER_USE_BOTH);
        if ($changed && $model->segments()->exists()) {
            throw new BillingException('Pricing of a plan with subscribers is immutable; create a new plan and migrate subscribers via change-plan.');
        }

        $model->update($data);   // Eloquent event -> PlanObserver -> cache invalidated

        return response()->json(['data' => $model->fresh()]);
    }
}
