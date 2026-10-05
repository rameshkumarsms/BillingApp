<?php

namespace App\Services;

use App\Billing\BillingCycle;
use App\Billing\DTO\BillingSegment;
use App\Billing\InvoiceCalculator;
use App\Billing\Money;
use App\Models\Customer;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Merchant dashboard. Reads ONLY the daily rollups (never raw events), so cost scales with
 * customers x days, not events. Result is cached briefly per merchant/day.
 */
class DashboardService
{
    public function __construct(
        private PlanCache $plans,
        private SegmentResolver $resolver,
        private InvoiceCalculator $calculator,
        private UsageRepository $usage,
    ) {}

    public function get(int $merchantId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return Cache::remember(self::key($merchantId, $today), config('billing.dashboard_cache_ttl'),
            fn () => $this->build($merchantId, $today));
    }

    public function forget(int $merchantId, ?CarbonImmutable $today = null): void
    {
        Cache::forget(self::key($merchantId, $today ?? CarbonImmutable::today()));
    }

    public function build(int $merchantId, CarbonImmutable $today): array
    {
        $monthStart = $today->startOfMonth();
        $projection = $this->projection($merchantId, $today);

        return [
            'as_of' => $today->toDateString(),
            'summary' => [
                'current_cycle_usage' => $projection['usage'],
                'current_cycle_allowance' => $projection['allowance'],
                'projected_overage_revenue_minor' => $projection['overage_minor'],
                'currency' => config('billing.currency'),
                'active_plans' => array_column($this->plans->forMerchant($merchantId), 'name'),
            ],
            'top_customers' => $this->topCustomers($merchantId, $monthStart, $today),
            'churn_risk' => $this->churnRisk($merchantId, $monthStart, $today),
            'daily_trend' => $this->dailyTrend($merchantId, $today),
        ];
    }

    private function activeCustomerSubquery(int $merchantId): \Closure
    {
        return fn ($q) => $q->from('subscriptions')->select('customer_id')
            ->where('merchant_id', $merchantId)->where('status', 'active');
    }

    private function topCustomers(int $merchantId, CarbonImmutable $monthStart, CarbonImmutable $today): array
    {
        $top = DB::table('usage_daily_aggregates')
            ->where('merchant_id', $merchantId)
            ->whereBetween('usage_date', [$monthStart->toDateString(), $today->toDateString()])
            ->whereIn('customer_id', $this->activeCustomerSubquery($merchantId))
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(total_units) AS units')
            ->orderByDesc('units')->limit(5)->get();

        $ids = $top->pluck('customer_id')->all();
        $names = Customer::query()->whereIn('id', $ids)->pluck('name', 'id');
        $included = $this->currentIncludedUnits($ids, $today);

        return $top->map(function ($r) use ($names, $included) {
            $units = (int) $r->units;
            $inc = $included[$r->customer_id] ?? 0;

            return [
                'customer_id' => (int) $r->customer_id,
                'customer' => $names[$r->customer_id] ?? null,
                'usage' => $units,
                'included_units' => $inc,
                'percent_of_allowance' => $inc > 0 ? round($units / $inc * 100, 1) : null,
            ];
        })->all();
    }

    /** @param int[] $customerIds @return array<int,int> customer_id => included units of the plan active today */
    private function currentIncludedUnits(array $customerIds, CarbonImmutable $today): array
    {
        if ($customerIds === []) {
            return [];
        }
        $d = $today->toDateString();
        $rows = DB::table('subscription_segments as g')
            ->join('subscriptions as s', 's.id', '=', 'g.subscription_id')
            ->whereIn('s.customer_id', $customerIds)->where('s.status', 'active')
            ->where('g.starts_on', '<=', $d)
            ->where(fn ($q) => $q->whereNull('g.ends_on')->orWhere('g.ends_on', '>=', $d))
            ->get(['s.customer_id', 'g.plan_id']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->customer_id] = $this->plans->terms($r->plan_id)->includedUnits;
        }

        return $out;
    }

    /**
     * Churn risk = month-to-date usage < 50% of the SAME number of days last month
     * (like-for-like; comparing a partial month with a full one would flag everyone on day 3).
     */
    private function churnRisk(int $merchantId, CarbonImmutable $monthStart, CarbonImmutable $today): array
    {
        $prevStart = $monthStart->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $prevStart->addDays($today->day - 1)->min($prevStart->endOfMonth()->startOfDay());

        $rows = DB::table('usage_daily_aggregates')
            ->where('merchant_id', $merchantId)
            ->whereBetween('usage_date', [$prevStart->toDateString(), $today->toDateString()])
            ->whereIn('customer_id', $this->activeCustomerSubquery($merchantId))
            ->groupBy('customer_id')
            ->selectRaw(
                'customer_id,
                 SUM(CASE WHEN usage_date >= ? THEN total_units ELSE 0 END) AS current_units,
                 SUM(CASE WHEN usage_date BETWEEN ? AND ? THEN total_units ELSE 0 END) AS previous_units',
                [$monthStart->toDateString(), $prevStart->toDateString(), $prevEnd->toDateString()],
            )
            ->havingRaw('previous_units >= ? AND current_units * 2 < previous_units', [config('billing.churn_min_previous_units')])
            ->orderByRaw('(previous_units - current_units) DESC')
            ->get();

        $names = Customer::query()->whereIn('id', $rows->pluck('customer_id'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'customer_id' => (int) $r->customer_id,
            'customer' => $names[$r->customer_id] ?? null,
            'current_units' => (int) $r->current_units,
            'previous_units' => (int) $r->previous_units,
            'drop_percent' => (int) round((1 - $r->current_units / $r->previous_units) * 100),
        ])->all();
    }

    /** @return array<int, array{date:string, units:int}> last 30 days, zero-filled */
    private function dailyTrend(int $merchantId, CarbonImmutable $today): array
    {
        $from = $today->subDays(29);
        $byDay = DB::table('usage_daily_aggregates')
            ->where('merchant_id', $merchantId)
            ->whereBetween('usage_date', [$from->toDateString(), $today->toDateString()])
            ->groupBy('usage_date')->selectRaw('usage_date, SUM(total_units) AS units')
            ->pluck('units', 'usage_date');

        $out = [];
        for ($d = $from; $d->lte($today); $d = $d->addDay()) {
            $out[] = ['date' => $d->toDateString(), 'units' => (int) ($byDay[$d->toDateString()] ?? 0)];
        }

        return $out;
    }

    /**
     * Projected overage for the current cycle. For each active subscription: extrapolate the
     * current daily run-rate across the remaining days of the cycle, then run the SAME
     * InvoiceCalculator the real invoice uses, so projection and invoice cannot drift apart.
     * Subscriptions are processed in chunks with one grouped usage query per chunk (no N+1).
     *
     * @return array{usage:int, allowance:int, overage_minor:int}
     */
    private function projection(int $merchantId, CarbonImmutable $today): array
    {
        $totals = ['usage' => 0, 'allowance' => 0, 'overage_minor' => 0];

        Subscription::query()->where('merchant_id', $merchantId)->active()->with('segments')
            ->chunkById(config('billing.aggregate_chunk'), function (Collection $subs) use (&$totals, $today) {
                $subs = $subs->filter(fn ($s) => $s->segments->isNotEmpty());
                if ($subs->isEmpty()) {
                    return;
                }
                $from = $subs->map(fn ($s) => BillingCycle::bounds($this->cycleType($s), $today)[0])->sort()->first();
                $rows = $this->usage->dailyRows($subs->pluck('customer_id')->all(), $from, $today)->groupBy('customer_id');

                foreach ($subs as $sub) {
                    $p = $this->projectSubscription($sub, $rows->get($sub->customer_id, collect()), $today);
                    if ($p) {
                        $totals['usage'] += $p['usage'];
                        $totals['allowance'] += $p['allowance'];
                        $totals['overage_minor'] += $p['overage_minor'];
                    }
                }
            });

        return $totals;
    }

    private function cycleType(Subscription $sub): string
    {
        return $this->plans->terms($sub->segments->first()->plan_id)->billingCycle;
    }

    /** @return array{usage:int, allowance:int, overage_minor:int}|null */
    private function projectSubscription(Subscription $sub, Collection $rows, CarbonImmutable $today): ?array
    {
        [$cycleStart, $cycleEnd] = BillingCycle::bounds($this->cycleType($sub), $today);
        $periodStart = $sub->started_on->max($cycleStart);
        if ($periodStart->gt($today)) {
            return null;
        }
        $resolved = $this->resolver->resolve($sub, $periodStart, $cycleEnd);
        if ($resolved === []) {
            return null;
        }

        // Run-rate = usage so far in the segment that contains today / days elapsed in it.
        $num = 0;
        $den = 1;
        foreach ($resolved as $s) {
            if ($s['start']->lte($today) && $s['end']->gte($today)) {
                $num = $this->usage->sum($rows, $s['start'], $today);
                $den = BillingCycle::days($s['start'], $today);
            }
        }

        $segments = [];
        $usageToDate = 0;
        $allowance = 0;
        $cycleDays = BillingCycle::days($cycleStart, $cycleEnd);
        foreach ($resolved as $s) {
            $terms = $this->plans->terms($s['plan_id']);
            if ($s['end']->lte($today)) {                                   // fully elapsed: actuals
                $u = $this->usage->sum($rows, $s['start'], $s['end']);
                $usageToDate += $u;
            } elseif ($s['start']->lte($today)) {                           // in progress: actual + run-rate for the rest
                $actual = $this->usage->sum($rows, $s['start'], $today);
                $usageToDate += $actual;
                $u = $actual + Money::mulDivRound($num, BillingCycle::daysBetween($today, $s['end']), $den);
            } else {                                                        // scheduled future segment: run-rate only
                $u = Money::mulDivRound($num, BillingCycle::days($s['start'], $s['end']), $den);
            }
            $segments[] = new BillingSegment($terms, $s['start'], $s['end'], $u);
            $allowance += InvoiceCalculator::allowance($terms, BillingCycle::days($s['start'], $s['end']), $cycleDays);
        }

        $result = $this->calculator->calculate($segments, $cycleDays);

        return ['usage' => $usageToDate, 'allowance' => $allowance, 'overage_minor' => $result->overageMinor()];
    }

    private static function key(int $merchantId, CarbonImmutable $day): string
    {
        return "dashboard:{$merchantId}:{$day->toDateString()}";
    }
}
