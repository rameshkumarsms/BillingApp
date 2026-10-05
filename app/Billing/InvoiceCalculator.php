<?php

namespace App\Billing;

use App\Billing\DTO\BillingSegment;
use App\Billing\DTO\InvoiceLineData;
use App\Billing\DTO\InvoiceResult;
use App\Billing\DTO\PlanTerms;

/**
 * Pure billing maths: no DB, no clock, no cache. Everything the invoice needs is passed in,
 * which is what makes proration / overage / plan-change edge cases cheap to unit test.
 *
 * Per segment (a run of days on one plan):
 *   base      = round(base_price      * segmentDays / cycleDays)
 *   allowance = round(included_units  * segmentDays / cycleDays)
 *   overage   = max(0, usage - allowance) * overage_rate
 * Usage before a plan change is therefore billed at the OLD plan's rate and allowance,
 * usage after at the NEW plan's. Unused allowance does not roll across segments (A5).
 */
final class InvoiceCalculator
{
    /** @param BillingSegment[] $segments */
    public function calculate(array $segments, int $cycleDays): InvoiceResult
    {
        if ($segments === []) {
            throw new \InvalidArgumentException('At least one billing segment is required.');
        }

        $lines = [];
        foreach ($segments as $seg) {
            $days = $seg->days();
            if ($days < 1 || $days > $cycleDays) {
                throw new \InvalidArgumentException("Segment of {$days} days is invalid for a {$cycleDays}-day cycle.");
            }
            $p = $seg->plan;
            $range = $seg->start->toDateString().' → '.$seg->end->toDateString();

            $lines[] = new InvoiceLineData(
                InvoiceLineData::BASE, $p->planId,
                sprintf('%s base fee (%d/%d days, %s)', $p->name, $days, $cycleDays, $range),
                $days, 0, Money::mulDivRound($p->basePriceMinor, $days, $cycleDays),
                $seg->start, $seg->end,
            );

            $overageUnits = max(0, $seg->usageUnits - self::allowance($p, $days, $cycleDays));
            if ($overageUnits > 0) {
                $lines[] = new InvoiceLineData(
                    InvoiceLineData::OVERAGE, $p->planId,
                    sprintf('%s overage: %d units (%s)', $p->name, $overageUnits, $range),
                    $overageUnits, $p->overageRateMicros,
                    Money::mulDivRound($overageUnits, $p->overageRateMicros, Money::MICROS_PER_MINOR),
                    $seg->start, $seg->end,
                );
            }
        }

        return new InvoiceResult($lines);
    }

    public static function allowance(PlanTerms $plan, int $segmentDays, int $cycleDays): int
    {
        return Money::mulDivRound($plan->includedUnits, $segmentDays, $cycleDays);
    }
}
