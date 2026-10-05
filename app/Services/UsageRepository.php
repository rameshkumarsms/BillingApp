<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read side over the daily rollups. */
class UsageRepository
{
    /** @param int[] $customerIds @return Collection<int, object{customer_id:int, usage_date:string, total_units:int}> */
    public function dailyRows(array $customerIds, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return DB::table('usage_daily_aggregates')
            ->whereIn('customer_id', $customerIds)
            ->whereBetween('usage_date', [$from->toDateString(), $to->toDateString()])
            ->get(['customer_id', 'usage_date', 'total_units']);
    }

    /** Sum the pre-fetched daily rows that fall in [from, to] (Y-m-d strings compare lexicographically). */
    public function sum(Collection $rows, CarbonInterface $from, CarbonInterface $to): int
    {
        $f = $from->toDateString();
        $t = $to->toDateString();

        return (int) $rows->filter(fn ($r) => $r->usage_date >= $f && $r->usage_date <= $t)->sum('total_units');
    }
}
