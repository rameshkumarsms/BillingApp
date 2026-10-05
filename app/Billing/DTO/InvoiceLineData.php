<?php

namespace App\Billing\DTO;

use Carbon\CarbonImmutable;

final readonly class InvoiceLineData
{
    public const BASE = 'base';
    public const OVERAGE = 'overage';

    public function __construct(
        public string $type,
        public int $planId,
        public string $description,
        public int $quantity,           // base: days billed, overage: units
        public int $unitRateMicros,     // 0 for base
        public int $amountMinor,
        public CarbonImmutable $segmentStart,
        public CarbonImmutable $segmentEnd,
    ) {}
}
