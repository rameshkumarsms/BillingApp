<?php

namespace Tests\Support;

use App\Actions\SubscribeCustomer;
use App\Billing\DTO\RecordUsageData;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\UsageRecorder;
use Carbon\CarbonImmutable;

trait BillingFixtures
{
    protected function makeMerchant(string $key = 'test-key', string $name = 'Acme'): Merchant
    {
        return Merchant::query()->create(['name' => $name, 'api_key_hash' => hash('sha256', $key)]);
    }

    protected function makePlan(Merchant $m, array $o = []): Plan
    {
        return Plan::query()->create($o + [
            'merchant_id' => $m->id, 'name' => 'Growth', 'billing_cycle' => 'monthly',
            'base_price_minor' => 100000, 'included_units' => 1000, 'overage_rate_micros' => 500000,
            'currency' => 'INR', 'is_active' => true,
        ]);
    }

    protected function makeCustomer(Merchant $m, string $name = 'Beta'): Customer
    {
        return Customer::query()->create(['merchant_id' => $m->id, 'name' => $name, 'email' => strtolower($name).'@example.com']);
    }

    protected function subscribe(Customer $c, Plan $p, string $startedOn): Subscription
    {
        return app(SubscribeCustomer::class)->execute($c, $p, CarbonImmutable::parse($startedOn));
    }

    protected function addUsage(Customer $c, string $date, int $qty, ?string $key = null): bool
    {
        return app(UsageRecorder::class)->record(new RecordUsageData(
            $c->merchant_id, $c->id, $qty, $date, $key ?? "k-{$c->id}-{$date}-".uniqid(),
        ));
    }
}
