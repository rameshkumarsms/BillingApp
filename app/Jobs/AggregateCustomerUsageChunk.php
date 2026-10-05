<?php

namespace App\Jobs;

use App\Services\UsageAggregator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One bounded unit of work: N customers x a date window. Idempotent (recompute + upsert), safe to retry. */
class AggregateCustomerUsageChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    /** @param int[] $customerIds */
    public function __construct(public array $customerIds, public string $from, public string $to)
    {
        $this->onQueue('aggregation');
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(UsageAggregator $aggregator): void
    {
        $aggregator->aggregateForCustomers($this->customerIds, \Carbon\CarbonImmutable::parse($this->from), \Carbon\CarbonImmutable::parse($this->to));
    }
}
