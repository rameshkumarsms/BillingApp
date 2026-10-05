<?php

namespace App\Billing\DTO;

final readonly class RecordUsageData
{
    public function __construct(
        public int $merchantId,
        public int $customerId,
        public int $quantity,
        public string $usageDate,        // Y-m-d
        public string $idempotencyKey,
    ) {}
}
