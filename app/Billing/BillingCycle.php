<?php

namespace App\Billing;

use Carbon\CarbonImmutable;

/** Calendar-aligned cycles (assumption A1 in README). */
final class BillingCycle
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} inclusive [start, end] dates */
    public static function bounds(string $cycle, CarbonImmutable $date): array
    {
        $d = $date->startOfDay();

        return $cycle === 'yearly'
            ? [$d->startOfYear(), $d->endOfYear()->startOfDay()]
            : [$d->startOfMonth(), $d->endOfMonth()->startOfDay()];
    }

    /** Signed whole-day difference (b - a). Timestamp math: app runs in UTC. */
    public static function daysBetween(CarbonImmutable $a, CarbonImmutable $b): int
    {
        return intdiv($b->startOfDay()->getTimestamp() - $a->startOfDay()->getTimestamp(), 86400);
    }

    /** Inclusive day count. */
    public static function days(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return self::daysBetween($start, $end) + 1;
    }
}
