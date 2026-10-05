<?php

namespace App\Console\Commands;

use App\Jobs\GenerateInvoiceChunk;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateInvoices extends Command
{
    protected $signature = 'billing:generate-invoices {--today= : Y-m-d override (testing/backfill)}';
    protected $description = 'Dispatch chunked jobs that invoice every completed billing cycle';

    public function handle(): int
    {
        $today = $this->option('today') ?: CarbonImmutable::today()->toDateString();
        $jobs = 0;

        Subscription::query()->active()->select('id')->chunkById(config('billing.aggregate_chunk'), function ($subs) use ($today, &$jobs) {
            GenerateInvoiceChunk::dispatch($subs->pluck('id')->all(), $today);
            $jobs++;
        });

        $this->info("Dispatched {$jobs} invoice job(s) as of {$today}.");

        return self::SUCCESS;
    }
}
