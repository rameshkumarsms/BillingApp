<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

class UsageEndpointTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    private function send(array $body, string $key = 'test-key', array $headers = [])
    {
        return $this->postJson('/api/usage', $body, ['X-Api-Key' => $key] + $headers);
    }

    public function test_records_event_and_replay_is_idempotent(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $body = ['customer_id' => $c->id, 'quantity' => 10];

        $this->send($body, 'test-key', ['Idempotency-Key' => 'req-1'])->assertCreated()->assertJsonPath('data.duplicate', false);
        $this->send($body, 'test-key', ['Idempotency-Key' => 'req-1'])->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, DB::table('usage_events')->count());
        $this->assertSame(10, (int) DB::table('usage_events')->sum('quantity'));
    }

    public function test_same_key_for_different_customers_is_not_a_duplicate(): void
    {
        $m = $this->makeMerchant();
        $a = $this->makeCustomer($m, 'Alpha');
        $b = $this->makeCustomer($m, 'Bravo');
        $this->send(['customer_id' => $a->id, 'quantity' => 1, 'idempotency_key' => 'same'])->assertCreated();
        $this->send(['customer_id' => $b->id, 'quantity' => 1, 'idempotency_key' => 'same'])->assertCreated();
        $this->assertSame(2, DB::table('usage_events')->count());
    }

    public function test_requires_idempotency_key_and_valid_quantity(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $this->send(['customer_id' => $c->id, 'quantity' => 5])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->send(['customer_id' => $c->id, 'quantity' => 0, 'idempotency_key' => 'x'])->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_rejects_dates_outside_window(): void
    {
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        $old = CarbonImmutable::today()->subDays(10)->toDateString();
        $this->send(['customer_id' => $c->id, 'quantity' => 1, 'idempotency_key' => 'o', 'usage_date' => $old])
            ->assertStatus(422)->assertJsonValidationErrors('usage_date');
    }

    public function test_cannot_write_usage_for_another_merchants_customer(): void
    {
        $this->makeMerchant('test-key', 'Mine');
        $other = $this->makeMerchant('other-key', 'Other');
        $theirs = $this->makeCustomer($other);
        $this->send(['customer_id' => $theirs->id, 'quantity' => 1, 'idempotency_key' => 'k'])->assertNotFound();
        $this->assertSame(0, DB::table('usage_events')->count());
    }

    public function test_requires_valid_api_key(): void
    {
        $this->postJson('/api/usage', [])->assertUnauthorized();
        $this->send([], 'wrong')->assertUnauthorized();
    }

    public function test_rate_limit_returns_429_after_threshold(): void
    {
        config(['billing.usage_rate_limit' => 3]);
        $m = $this->makeMerchant();
        $c = $this->makeCustomer($m);
        for ($i = 1; $i <= 3; $i++) {
            $this->send(['customer_id' => $c->id, 'quantity' => 1, 'idempotency_key' => "r{$i}"])->assertCreated();
        }
        $this->send(['customer_id' => $c->id, 'quantity' => 1, 'idempotency_key' => 'r4'])->assertStatus(429);
    }
}
