<?php

namespace App\Billing\DTO;

use App\Billing\BillingCycle;
use Carbon\CarbonImmutable;

/** A contiguous run of days on ONE plan, with the usage recorded in that run. */
final readonly class BillingSegment
{
    public function __construct(
        public PlanTerms $plan,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $usageUnits,
    ) {}

    public function days(): int
    {
        return BillingCycle::days($this->start, $this->end);
    }
}
