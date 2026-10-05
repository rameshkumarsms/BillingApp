<?php

namespace App\Actions;

use App\Exceptions\BillingException;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SubscribeCustomer
{
    public function __construct(private DashboardService $dashboard) {}

    public function execute(Customer $customer, Plan $plan, CarbonImmutable $startsOn): Subscription
    {
        if ($customer->merchant_id !== $plan->merchant_id) {
            throw new BillingException('Plan and customer belong to different merchants.');
        }
        if (! $plan->is_active) {
            throw new BillingException('Plan is not active.');
        }

        $sub = DB::transaction(function () use ($customer, $plan, $startsOn) {
            // Lock the customer row to serialise concurrent subscribe calls (one active subscription, A2).
            Customer::query()->lockForUpdate()->find($customer->id);
            if (Subscription::query()->where('customer_id', $customer->id)->active()->exists()) {
                throw new BillingException('Customer already has an active subscription.');
            }

            $sub = Subscription::query()->create([
                'merchant_id' => $customer->merchant_id, 'customer_id' => $customer->id,
                'started_on' => $startsOn->toDateString(), 'status' => 'active',
            ]);
            $sub->segments()->create(['plan_id' => $plan->id, 'starts_on' => $startsOn->toDateString(), 'ends_on' => null]);

            return $sub;
        });

        $this->dashboard->forget($customer->merchant_id);

        return $sub;
    }
}
