<?php

namespace App\Observers;

use App\Models\Plan;
use App\Services\PlanCache;

class PlanObserver
{
    public function __construct(private PlanCache $cache) {}

    public function saved(Plan $plan): void
    {
        $this->cache->forget($plan->id, $plan->merchant_id);
    }

    public function deleted(Plan $plan): void
    {
        $this->cache->forget($plan->id, $plan->merchant_id);
    }
}
