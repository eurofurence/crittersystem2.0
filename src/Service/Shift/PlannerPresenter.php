<?php

namespace App\Service\Shift;

use App\Entity\Shift;

/**
 * Builds the Standard Planner grid view model: a list of day
 * columns (each tagged with its event phase for distinct setup/teardown styling)
 * and shift blocks positioned as percentages of a 24-hour day. All positioning
 * is done in the event's display timezone so wall-clock times line up on the grid
 * while the model stays UTC.
 */
final class PlannerPresenter
{
    private const MINUTES_PER_DAY = 1440;

    /**
     * Parallel shifts share the width of their day column.
     */
    public const MAX_LANES = 4;

    /**
     * @param Shift[]  $shifts
     * @param string[] $expandedDays ISO dates (Y-m-d) to lay out without the lane cap
     *
     * @return array{
     *     days: list<array{iso: string, label: string, phase: string, expanded: bool, hidden: int}>,
     *     blocks: list<array{shift: Shift, dayIndex: int, top: float, height: float, overnight: bool, lane: int, lanes: int, left: float, width: float}>,
     *     rasterMinutes: int
     * }
     */
    public function buildGrid(
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        array $shifts,
        \DateTimeZone $tz,
        ?\DateTimeImmutable $eventStart = null,
        ?\DateTimeImmutable $eventEnd = null,
        array $expandedDays = [],
    ): array {
        $days = $this->days($rangeStart, $rangeEnd, $tz, $eventStart, $eventEnd);
        $expanded = array_fill_keys($expandedDays, true);
        $dayIndex = [];
        foreach ($days as $i => $day) {
            $dayIndex[$day['iso']] = $i;
            $days[$i]['expanded'] = isset($expanded[$day['iso']]);
            $days[$i]['hidden'] = 0;
        }

        /** @var array<int, list<array<string, mixed>>> $byDay */
        $byDay = [];
        foreach ($shifts as $shift) {
            $localStart = $shift->getStartsAt()->setTimezone($tz);
            $localEnd = $shift->getEndsAt()->setTimezone($tz);
            $iso = $localStart->format('Y-m-d');
            if (!isset($dayIndex[$iso])) {
                continue; // starts outside the visible range
            }

            $startMinutes = (int) $localStart->format('H') * 60 + (int) $localStart->format('i');
            $endMinutes = $startMinutes + (int) round(($localEnd->getTimestamp() - $localStart->getTimestamp()) / 60);
            $overnight = $endMinutes > self::MINUTES_PER_DAY;
            // Lanes are derived from the CLAMPED span, the part actually drawn in this column.
            // Using the true end would let an overnight shift reserve width on a day where it is
            // not visible.
            $clampedEnd = min($endMinutes, self::MINUTES_PER_DAY);

            $byDay[$dayIndex[$iso]][] = [
                'shift' => $shift,
                'dayIndex' => $dayIndex[$iso],
                'dayIso' => $iso,
                'startMin' => $startMinutes,
                'endMin' => max($startMinutes + 1, $clampedEnd),
                'top' => round($startMinutes / self::MINUTES_PER_DAY * 100, 4),
                'height' => round(max(1, $clampedEnd - $startMinutes) / self::MINUTES_PER_DAY * 100, 4),
                'overnight' => $overnight,
            ];
        }

        $blocks = [];
        ksort($byDay);
        foreach ($byDay as $index => $dayBlocks) {
            [$laid, $hidden] = $this->layOutDay($dayBlocks, isset($expanded[$days[$index]['iso']]));
            $blocks = array_merge($blocks, $laid);
            $days[$index]['hidden'] = $hidden;
        }

        return [
            'days' => $days,
            'blocks' => $blocks,
            'rasterMinutes' => PlannerDraftStore::RASTER_MINUTES,
        ];
    }

    /**
     * Place one day's blocks into lanes so that shifts running at the same time sit side by side
     * instead of on top of each other.
     *
     * Blocks are grouped into clusters of transitively overlapping shifts, and each cluster is
     * divided into as many lanes as it needs. Clustering matters: two shifts overlapping each other
     * must not narrow a third that merely runs later in the same day.
     *
     * Touching is not overlapping - a shift ending at 22:00 and one starting at 22:00 each keep the
     * full width - which is why the comparisons below are strict.
     *
     * @param list<array<string, mixed>> $dayBlocks
     *
     * @return array{0: list<array<string, mixed>>, 1: int} the placed blocks, and how many the cap hid
     */
    private function layOutDay(array $dayBlocks, bool $expanded): array
    {
        // A total order, so lanes cannot shuffle between two renders of the same data. Longest
        // first at equal starts is the usual calendar convention.
        usort($dayBlocks, static fn (array $a, array $b): int => [$a['startMin'], -$a['endMin'], $a['shift']->getId() ?? 0]
            <=> [$b['startMin'], -$b['endMin'], $b['shift']->getId() ?? 0]);

        $placed = [];
        $hiddenTotal = 0;

        foreach ($this->clusters($dayBlocks) as $cluster) {
            /** @var int[] $laneEnds end minute of the last block placed in each lane */
            $laneEnds = [];
            foreach ($cluster as $i => $block) {
                $lane = null;
                foreach ($laneEnds as $index => $endsAt) {
                    if ($endsAt <= $block['startMin']) {
                        $lane = $index;
                        break;
                    }
                }
                if ($lane === null) {
                    $lane = \count($laneEnds);
                }
                $laneEnds[$lane] = $block['endMin'];
                $cluster[$i]['lane'] = $lane;
            }

            $needed = \count($laneEnds);
            $lanes = $expanded ? $needed : min($needed, self::MAX_LANES);
            $width = round(100 / $lanes, 4);

            foreach ($cluster as $block) {
                if ($block['lane'] >= $lanes) {
                    ++$hiddenTotal;
                    continue;
                }
                $block['lanes'] = $lanes;
                $block['left'] = round($block['lane'] * $width, 4);
                $block['width'] = $width;
                unset($block['startMin'], $block['endMin'], $block['dayIso']);
                $placed[] = $block;
            }
        }

        return [$placed, $hiddenTotal];
    }

    /**
     * Split a day's blocks (already in start order) into runs of transitively overlapping shifts.
     *
     * @param list<array<string, mixed>> $dayBlocks
     *
     * @return list<list<array<string, mixed>>>
     */
    private function clusters(array $dayBlocks): array
    {
        $clusters = [];
        $current = [];
        $clusterEnd = null;

        foreach ($dayBlocks as $block) {
            if ($current !== [] && $clusterEnd !== null && $block['startMin'] >= $clusterEnd) {
                $clusters[] = $current;
                $current = [];
                $clusterEnd = null;
            }
            $current[] = $block;
            $clusterEnd = max($clusterEnd ?? 0, $block['endMin']);
        }
        if ($current !== []) {
            $clusters[] = $current;
        }

        return $clusters;
    }

    /**
     * The day columns for a range, tagged with event phase. Public so the Shift
     * Wizard can offer the same day list as the grid.
     *
     * @return list<array{iso: string, label: string, phase: string}>
     */
    public function dayList(
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        \DateTimeZone $tz,
        ?\DateTimeImmutable $eventStart = null,
        ?\DateTimeImmutable $eventEnd = null,
    ): array {
        return $this->days($rangeStart, $rangeEnd, $tz, $eventStart, $eventEnd);
    }

    /**
     * @return list<array{iso: string, label: string, phase: string}>
     */
    private function days(
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        \DateTimeZone $tz,
        ?\DateTimeImmutable $eventStart,
        ?\DateTimeImmutable $eventEnd,
    ): array {
        $day = $rangeStart->setTimezone($tz)->setTime(0, 0);
        $last = $rangeEnd->setTimezone($tz)->setTime(0, 0);
        $eventStartDay = $eventStart?->setTimezone($tz)->setTime(0, 0);
        $eventEndDay = $eventEnd?->setTimezone($tz)->setTime(0, 0);

        $days = [];
        // Cap the loop defensively so a bad range can never spin forever.
        for ($i = 0; $i < 120 && $day <= $last; ++$i) {
            $days[] = [
                'iso' => $day->format('Y-m-d'),
                'label' => $day->format('D j M'),
                'phase' => $this->phase($day, $eventStartDay, $eventEndDay),
            ];
            $day = $day->modify('+1 day');
        }

        return $days;
    }

    private function phase(\DateTimeImmutable $day, ?\DateTimeImmutable $eventStartDay, ?\DateTimeImmutable $eventEndDay): string
    {
        if ($eventStartDay === null || $eventEndDay === null) {
            return CheckInPolicy::PHASE_MAIN;
        }
        if ($day < $eventStartDay) {
            return CheckInPolicy::PHASE_SETUP;
        }
        if ($day >= $eventEndDay) {
            return CheckInPolicy::PHASE_TEARDOWN;
        }

        return CheckInPolicy::PHASE_MAIN;
    }
}
