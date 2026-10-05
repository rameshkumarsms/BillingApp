<?php

namespace App\Jobs;

use App\Services\InvoiceGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generates all due invoices for a chunk of subscriptions. One bad subscription must not block the
 * other 499, so failures are logged and skipped; the next scheduled run retries them (generation is idempotent).
 */
class GenerateInvoiceChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    /** @param int[] $subscriptionIds */
    public function __construct(public array $subscriptionIds, public string $today)
    {
        $this->onQueue('billing');
    }

    public function handle(InvoiceGenerator $generator): void
    {
        $today = CarbonImmutable::parse($this->today);
        foreach ($this->subscriptionIds as $id) {
            try {
                $generator->generateAllDue($id, $today);
            } catch (\Throwable $e) {
                Log::error('Invoice generation failed', ['subscription_id' => $id, 'error' => $e->getMessage()]);
            }
        }
    }
}
