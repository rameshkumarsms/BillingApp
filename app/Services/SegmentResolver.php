<?php

namespace App\Services;

use App\Models\Subscription;
use Carbon\CarbonImmutable;

/** Clips a subscription's plan history to a period -> "which plan applied on which days". */
class SegmentResolver
{
    /** @return array<int, array{plan_id:int, start:CarbonImmutable, end:CarbonImmutable}> */
    public function resolve(Subscription $sub, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $out = [];
        foreach ($sub->segments as $seg) {
            $start = $seg->starts_on->max($periodStart);
            $end = ($seg->ends_on ?? $periodEnd)->min($periodEnd);
            if ($start->gt($end)) {
                continue;
            }
            $out[] = ['plan_id' => (int) $seg->plan_id, 'start' => $start, 'end' => $end];
        }

        return $out;
    }
}
