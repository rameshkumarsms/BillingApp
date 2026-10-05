<?php

namespace App\Providers;

use App\Models\Plan;
use App\Observers\PlanObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Plan::observe(PlanObserver::class);

        // Per API key (falls back to IP). Evaluated per request so the limit is configurable at runtime.
        RateLimiter::for('usage', fn (Request $r) => Limit::perMinute(config('billing.usage_rate_limit'))
            ->by(sha1((string) ($r->header('X-Api-Key') ?: $r->ip()))));
    }
}
