<?php

namespace App\Service;

/**
 * The deduplicated worked-hour result for a set of shift entries.
 * Day and night hours already have their multipliers applied; overlapping
 * elapsed time is counted once and a no-show penalty is a separate additive term.
 */
final readonly class HoursBreakdown
{
    public function __construct(
        public float $dayHours,
        public float $nightHours,
        public float $noshowPenaltyHours,
        public int $completedCount,
        public int $nightCount,
        public int $noshowCount,
    ) {
    }

    /** Rewarded hours excluding worklogs: day + night (multiplied) + penalty. */
    public function total(): float
    {
        return $this->dayHours + $this->nightHours + $this->noshowPenaltyHours;
    }
}
