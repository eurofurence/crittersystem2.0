<?php

namespace App\Service;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Repository\ShiftEntryRepository;
use App\Repository\WorklogRepository;

/**
 * Worked-hours calculation:
 *   SUM(base_hours x night_multiplier x penalty_multiplier) + worklog hours
 *
 * - base hours = shift duration
 * - night multiplier = x2 when the shift overlaps the night window (02:00-08:00)
 * - no-show penalty = x-2 (negative hours)
 */
final class HoursCalculator
{
    // TODO: Make this as parameters
    private const NIGHT_START_HOUR = 2;
    private const NIGHT_END_HOUR = 8;
    public const NIGHT_MULTIPLIER = 2.0;
    public const NOSHOW_MULTIPLIER = -2.0;

    public function __construct(
        private readonly ShiftEntryRepository $entries,
        private readonly WorklogRepository $worklogs,
    ) {
    }

    /** Credited hours for a single shift entry (negative when a no-show). */
    public function entryHours(ShiftEntry $entry): float
    {
        $base = $entry->getShift()->getDurationHours();

        if ($entry->isNoshow()) {
            return $base * self::NOSHOW_MULTIPLIER;
        }

        return $base * ($this->overlapsNight($entry->getShift()) ? self::NIGHT_MULTIPLIER : 1.0);
    }

    /** Total credited hours for a user: shift entries + manual worklogs. */
    public function totalForUser(User $user): float
    {
        $total = $this->worklogs->sumHoursForUser($user);
        foreach ($this->entries->findByUserOrdered($user) as $entry) {
            $total += $this->entryHours($entry);
        }

        return $total;
    }

    public function overlapsNight(Shift $shift): bool
    {
        $start = $shift->getStartsAt();
        $end = $shift->getEndsAt();

        // Walk each calendar day the shift touches and test the night window.
        $day = $start->setTime(0, 0);
        for ($i = 0; $i < 14 && $day <= $end; ++$i) {
            $nightStart = $day->setTime(self::NIGHT_START_HOUR, 0);
            $nightEnd = $day->setTime(self::NIGHT_END_HOUR, 0);
            if ($start < $nightEnd && $end > $nightStart) {
                return true;
            }
            $day = $day->modify('+1 day');
        }

        return false;
    }
}
