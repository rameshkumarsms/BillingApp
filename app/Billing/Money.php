<?php

namespace App\Billing;

/**
 * All money is integer minor units (paise). Overage rates are integer "micros"
 * (1/1,000,000 of a currency unit) so fractional per-unit prices like ₹0.0035 stay exact.
 */
final class Money
{
    public const MICROS_PER_MINOR = 10_000; // 1 paise = 10,000 micro-rupees

    /** round-half-up(a * b / c) using integer math only. */
    public static function mulDivRound(int $a, int $b, int $c): int
    {
        if ($c <= 0) {
            throw new \InvalidArgumentException('Divisor must be positive.');
        }

        return intdiv(2 * $a * $b + $c, 2 * $c);
    }
}
