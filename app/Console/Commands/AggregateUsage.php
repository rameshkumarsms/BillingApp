<?php

namespace App\Console\Commands;

use App\Jobs\AggregateCustomerUsageChunk;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AggregateUsage extends Command
{
    protected $signature = 'usage:aggregate {--from= : Y-m-d (default: yesterday)} {--to= : Y-m-d (default: today)}';
    protected $description = 'Dispatch chunked jobs that roll raw usage events into daily aggregates';

    public function handle(): int
    {
        $from = $this->option('from') ?: CarbonImmutable::yesterday()->toDateString();
        $to = $this->option('to') ?: CarbonImmutable::today()->toDateString();
        $jobs = 0;

        Customer::query()->select('id')->chunkById(config('billing.aggregate_chunk'), function ($customers) use ($from, $to, &$jobs) {
            AggregateCustomerUsageChunk::dispatch($customers->pluck('id')->all(), $from, $to);
            $jobs++;
        });

        $this->info("Dispatched {$jobs} aggregation job(s) for {$from}..{$to}.");

        return self::SUCCESS;
    }
}
