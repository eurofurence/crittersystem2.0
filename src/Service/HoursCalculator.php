<?php

namespace App\Service;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Repository\ShiftEntryRepository;
use App\Repository\WorklogRepository;

/**
 * Worked/rewarded-hours calculation with overlap deduplication.
 *
 * Base elapsed time is deduplicated: a user credited for overlapping shifts (or
 * multiple positions in one shift) is rewarded the union of the time once, never
 * the sum. On top of the deduplicated base the existing reward rules still apply:
 *
 * - night multiplier (default x2) for shifts touching the configured night
 *   window — applied per shift over its whole extent, and it governs any instant
 *   where an overlapping day and night shift coincide;
 * - no-show penalty (default x-2) as a separate additive term per no-show entry.
 *
 * Multipliers and the night window are read from {@see EventConfigStore} so an
 * admin can tune them; the constants are the fallback defaults.
 */
final class HoursCalculator
{
    public const NIGHT_MULTIPLIER = EventConfigStore::DEFAULT_HOURS_NIGHT_MULTIPLIER;
    public const NOSHOW_MULTIPLIER = EventConfigStore::DEFAULT_HOURS_NOSHOW_MULTIPLIER;

    public function __construct(
        private readonly ShiftEntryRepository $entries,
        private readonly WorklogRepository $worklogs,
        private readonly EventConfigStore $config,
    ) {
    }

    public function nightMultiplier(): float
    {
        return $this->config->getFloat(EventConfigStore::KEY_HOURS_NIGHT_MULTIPLIER, self::NIGHT_MULTIPLIER);
    }

    public function noshowMultiplier(): float
    {
        return $this->config->getFloat(EventConfigStore::KEY_HOURS_NOSHOW_MULTIPLIER, self::NOSHOW_MULTIPLIER);
    }

    /** Credited hours for a single shift entry (negative when a no-show). No dedup. */
    public function entryHours(ShiftEntry $entry): float
    {
        $base = $entry->getShift()->getDurationHours();

        if ($entry->isNoshow()) {
            return $base * $this->noshowMultiplier();
        }

        return $base * ($this->overlapsNight($entry->getShift()) ? $this->nightMultiplier() : 1.0);
    }

    /** Total credited hours for a user: deduplicated shift entries + manual worklogs. */
    public function totalForUser(User $user): float
    {
        $worklog = $this->worklogs->sumHoursForUser($user);

        return $worklog + $this->breakdown($this->entries->findByUserOrdered($user))->total();
    }

    /**
     * Deduplicated day/night/penalty breakdown for a set of entries.
     * Overlapping working intervals are merged so their shared time is rewarded
     * once; a no-show is a per-entry penalty and is not deduplicated.
     *
     * @param iterable<ShiftEntry> $entries
     */
    public function breakdown(iterable $entries): HoursBreakdown
    {
        $nightMult = $this->nightMultiplier();
        $noshowMult = $this->noshowMultiplier();

        /** @var list<array{0:int,1:int,2:float}> $working [start, end, multiplier] */
        $working = [];
        $noshowPenalty = 0.0;
        $completed = 0;
        $nightCount = 0;
        $noshowCount = 0;

        foreach ($entries as $entry) {
            $shift = $entry->getShift();
            if ($entry->isNoshow()) {
                $noshowPenalty += $shift->getDurationHours() * $noshowMult;
                ++$noshowCount;
                continue;
            }
            ++$completed;
            $isNight = $this->overlapsNight($shift);
            if ($isNight) {
                ++$nightCount;
            }
            $working[] = [
                $shift->getStartsAt()->getTimestamp(),
                $shift->getEndsAt()->getTimestamp(),
                $isNight ? $nightMult : 1.0,
            ];
        }

        [$dayHours, $nightHours] = $this->sweep($working, $nightMult);

        return new HoursBreakdown($dayHours, $nightHours, $noshowPenalty, $completed, $nightCount, $noshowCount);
    }

    /**
     * Sweep-line over the working intervals: for each elementary segment covered
     * by at least one interval, credit its duration once at the strongest
     * multiplier active there (night beats day on coincident overlaps).
     *
     * @param list<array{0:int,1:int,2:float}> $working
     *
     * @return array{0: float, 1: float} [dayHours, nightHours]
     */
    private function sweep(array $working, float $nightMult): array
    {
        $boundaries = [];
        foreach ($working as [$start, $end]) {
            $boundaries[$start] = true;
            $boundaries[$end] = true;
        }
        $boundaries = array_keys($boundaries);
        sort($boundaries);

        $dayHours = 0.0;
        $nightHours = 0.0;
        $count = \count($boundaries);
        for ($i = 0; $i + 1 < $count; ++$i) {
            $from = $boundaries[$i];
            $to = $boundaries[$i + 1];
            $mid = ($from + $to) / 2;

            $multiplier = 0.0;
            foreach ($working as [$start, $end, $mult]) {
                if ($start <= $mid && $mid < $end) {
                    $multiplier = max($multiplier, $mult);
                }
            }
            if ($multiplier <= 0.0) {
                continue; // gap between disjoint intervals
            }

            $duration = ($to - $from) / 3600;
            if ($nightMult > 1.0 && $multiplier >= $nightMult) {
                $nightHours += $duration * $multiplier;
            } else {
                $dayHours += $duration * $multiplier;
            }
        }

        return [$dayHours, $nightHours];
    }

    public function overlapsNight(Shift $shift): bool
    {
        $nightStartHour = $this->config->getInt(EventConfigStore::KEY_HOURS_NIGHT_START, EventConfigStore::DEFAULT_HOURS_NIGHT_START);
        $nightEndHour = $this->config->getInt(EventConfigStore::KEY_HOURS_NIGHT_END, EventConfigStore::DEFAULT_HOURS_NIGHT_END);

        $start = $shift->getStartsAt();
        $end = $shift->getEndsAt();

        // Walk each calendar day the shift touches and test the night window.
        $day = $start->setTime(0, 0);
        for ($i = 0; $i < 14 && $day <= $end; ++$i) {
            $nightStart = $day->setTime($nightStartHour, 0);
            $nightEnd = $day->setTime($nightEndHour, 0);
            if ($start < $nightEnd && $end > $nightStart) {
                return true;
            }
            $day = $day->modify('+1 day');
        }

        return false;
    }
}
