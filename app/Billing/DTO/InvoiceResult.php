<?php

namespace App\Billing\DTO;

final readonly class InvoiceResult
{
    /** @param InvoiceLineData[] $lines */
    public function __construct(public array $lines) {}

    public function totalMinor(): int
    {
        return array_sum(array_map(fn (InvoiceLineData $l) => $l->amountMinor, $this->lines));
    }

    public function overageMinor(): int
    {
        return array_sum(array_map(
            fn (InvoiceLineData $l) => $l->type === InvoiceLineData::OVERAGE ? $l->amountMinor : 0,
            $this->lines,
        ));
    }
}
