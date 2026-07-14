<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Enum\ShiftEntryState;
use App\Repository\ShiftRepository;

/**
 * Builds the staff schedule timeline view model: user names on the
 * Y-axis, time on the X-axis, colored blocks for confirmed assignments, with
 * location and accessible labels. Multi-day; positions are percentages of a
 * 24-hour day so both the interactive view and the PDF can lay them out.
 */
final class ScheduleTimelineService
{
    private const MINUTES_PER_DAY = 1440;

    public function __construct(
        private readonly ShiftRepository $shifts,
        private readonly PlannerPresenter $presenter,
    ) {
    }

    /**
     * @return array{
     *     days: list<array{iso: string, label: string, phase: string}>,
     *     rows: list<array{user: \App\Entity\User, blocks: list<array{dayIndex: int, left: float, width: float, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, title: string, location: ?string, overnight: bool}>}>
     * }
     */
    public function build(
        Department $department,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $tz,
        ?\DateTimeImmutable $eventStart = null,
        ?\DateTimeImmutable $eventEnd = null,
    ): array {
        $days = $this->presenter->dayList($from, $to, $tz, $eventStart, $eventEnd);
        $dayIndex = [];
        foreach ($days as $i => $day) {
            $dayIndex[$day['iso']] = $i;
        }

        /** @var array<int, array{user: \App\Entity\User, blocks: array<int, mixed>}> $rows */
        $rows = [];
        foreach ($this->shifts->findForDepartmentBetween($department, $from, $to) as $shift) {
            foreach ($shift->getEntries() as $entry) {
                if ($entry->getState() !== ShiftEntryState::ASSIGNMENT) {
                    continue;
                }
                $user = $entry->getUser();
                $localStart = $shift->getStartsAt()->setTimezone($tz);
                $localEnd = $shift->getEndsAt()->setTimezone($tz);
                $iso = $localStart->format('Y-m-d');
                if (!isset($dayIndex[$iso])) {
                    continue;
                }

                $startMin = (int) $localStart->format('H') * 60 + (int) $localStart->format('i');
                $endMin = $startMin + (int) round(($localEnd->getTimestamp() - $localStart->getTimestamp()) / 60);
                $overnight = $endMin > self::MINUTES_PER_DAY;
                $clampedEnd = min($endMin, self::MINUTES_PER_DAY);

                $rows[$user->getId()]['user'] = $user;
                $rows[$user->getId()]['blocks'][] = [
                    'dayIndex' => $dayIndex[$iso],
                    'left' => round($startMin / self::MINUTES_PER_DAY * 100, 4),
                    'width' => round(max(1, $clampedEnd - $startMin) / self::MINUTES_PER_DAY * 100, 4),
                    'startsAt' => $shift->getStartsAt(),
                    'endsAt' => $shift->getEndsAt(),
                    'title' => $shift->getTitle(),
                    'location' => $shift->getLocation()?->getName(),
                    'overnight' => $overnight,
                ];
            }
        }

        // Stable order by user name.
        $ordered = array_values($rows);
        usort($ordered, static fn ($a, $b) => strcasecmp($a['user']->getName(), $b['user']->getName()));

        return ['days' => $days, 'rows' => $ordered];
    }
}
