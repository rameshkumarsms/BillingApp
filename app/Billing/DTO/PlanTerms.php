<?php

namespace App\Billing\DTO;

final readonly class PlanTerms
{
    public function __construct(
        public int $planId,
        public string $name,
        public string $billingCycle,
        public int $basePriceMinor,
        public int $includedUnits,
        public int $overageRateMicros,
        public string $currency = 'INR',
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            (int) $a['planId'], $a['name'], $a['billingCycle'], (int) $a['basePriceMinor'],
            (int) $a['includedUnits'], (int) $a['overageRateMicros'], $a['currency'] ?? 'INR',
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
