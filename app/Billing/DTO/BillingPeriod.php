<?php

namespace App\Billing\DTO;

use Carbon\CarbonImmutable;

final readonly class BillingPeriod
{
    public function __construct(
        public CarbonImmutable $start,      // may be mid-cycle (first invoice)
        public CarbonImmutable $end,        // cycle end
        public int $cycleDays,              // length of the FULL calendar cycle (proration denominator)
    ) {}
}
