<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->char('api_key_hash', 64)->unique();      // sha256 of the API key; plaintext never stored
            $t->timestamps();
        });

        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('billing_cycle', 10)->default('monthly');     // monthly | yearly
            $t->unsignedBigInteger('base_price_minor');              // paise
            $t->unsignedBigInteger('included_units');
            $t->unsignedBigInteger('overage_rate_micros');           // 1e-6 currency units per unit
            $t->char('currency', 3)->default('INR');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['merchant_id', 'is_active']);
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('email');
            $t->timestamps();
            $t->unique(['merchant_id', 'email']);
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->date('started_on');
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->index(['customer_id', 'status']);
            $t->index(['merchant_id', 'status']);
        });

        // Plan history of a subscription. Plan changes append here instead of mutating the
        // subscription, so billing can replay exactly which plan applied on which day.
        Schema::create('subscription_segments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();             // null = open-ended (current plan)
            $t->timestamps();
            $t->index(['subscription_id', 'starts_on']);
        });

        // Hot write path. Deliberately NO foreign keys (each FK check costs a lookup per insert);
        // referential integrity is enforced in the application at the endpoint.
        Schema::create('usage_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_id');
            $t->unsignedBigInteger('customer_id');
            $t->string('idempotency_key', 64);
            $t->unsignedInteger('quantity');
            $t->date('usage_date');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['customer_id', 'idempotency_key'], 'usage_events_idem_unique');   // dedupe
            $t->index(['customer_id', 'usage_date'], 'usage_events_customer_date_idx');   // aggregation scan
        });

        // Daily rollup: (customer, day) -> total. Dashboard and invoicing read THIS, not raw events.
        Schema::create('usage_daily_aggregates', function (Blueprint $t) {
            $t->unsignedBigInteger('customer_id');
            $t->date('usage_date');
            $t->unsignedBigInteger('merchant_id');       // denormalised for merchant-wide dashboard queries
            $t->unsignedBigInteger('total_units');
            $t->unsignedInteger('event_count');
            $t->timestamp('computed_at')->nullable();
            $t->primary(['customer_id', 'usage_date']);
            $t->index(['merchant_id', 'usage_date', 'customer_id'], 'agg_merchant_date_idx');
        });
    }

    public function down(): void
    {
        foreach (['usage_daily_aggregates', 'usage_events', 'subscription_segments', 'subscriptions', 'customers', 'plans', 'merchants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
