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
     * @param Shift[] $shifts
     *
     * @return array{
     *     days: list<array{iso: string, label: string, phase: string}>,
     *     blocks: list<array{shift: Shift, dayIndex: int, top: float, height: float, overnight: bool}>,
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
    ): array {
        $days = $this->days($rangeStart, $rangeEnd, $tz, $eventStart, $eventEnd);
        $dayIndex = [];
        foreach ($days as $i => $day) {
            $dayIndex[$day['iso']] = $i;
        }

        $blocks = [];
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
            $clampedEnd = min($endMinutes, self::MINUTES_PER_DAY);

            $blocks[] = [
                'shift' => $shift,
                'dayIndex' => $dayIndex[$iso],
                'top' => round($startMinutes / self::MINUTES_PER_DAY * 100, 4),
                'height' => round(max(1, $clampedEnd - $startMinutes) / self::MINUTES_PER_DAY * 100, 4),
                'overnight' => $overnight,
            ];
        }

        return [
            'days' => $days,
            'blocks' => $blocks,
            'rasterMinutes' => PlannerDraftStore::RASTER_MINUTES,
        ];
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
