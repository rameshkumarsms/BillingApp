<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $t->date('period_start');
            $t->date('period_end');
            $t->char('currency', 3)->default('INR');
            $t->unsignedBigInteger('total_minor');
            $t->string('status', 16)->default('issued');
            $t->timestamp('generated_at');
            $t->timestamps();
            // Invoice generation idempotency: one invoice per subscription per period, enforced by the DB.
            $t->unique(['subscription_id', 'period_start']);
            $t->index(['merchant_id', 'period_start']);
        });

        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('type', 16);                       // base | overage
            $t->foreignId('plan_id')->constrained();
            $t->string('description');
            $t->unsignedBigInteger('quantity');
            $t->unsignedBigInteger('unit_rate_micros')->default(0);
            $t->unsignedBigInteger('amount_minor');
            $t->date('segment_start');
            $t->date('segment_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
