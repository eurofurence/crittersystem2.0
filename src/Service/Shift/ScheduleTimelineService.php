<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\User;
use App\Enum\ShiftEntryState;
use App\Repository\ShiftRepository;

/**
 * Builds the staff schedule: one column per person, one row per half hour.
 *
 * A shift occupies every slot it covers rather than one row, so reading down a column tells a
 * manager who is busy at a given moment without doing arithmetic. Only the hours the department
 * actually runs something are laid out, and a day with nothing on it is skipped: a full 24 hours per
 * day over a nine-day event is several hundred rows, nearly all of them empty.
 *
 * A staff shift nobody is assigned to gets a row of its own, because it belongs to no column: it is
 * exactly the thing the schedule exists to catch, and it would otherwise be invisible here.
 */
final class ScheduleTimelineService
{
    public const SLOT_MINUTES = 30;

    private const MINUTES_PER_DAY = 1440;

    public function __construct(private readonly ShiftRepository $shifts)
    {
    }

    /**
     * @return array{
     *     users: list<User>,
     *     rows: list<array{
     *         kind: string,
     *         dayLabel: ?string,
     *         time: ?string,
     *         isDayStart: bool,
     *         cells: array<int, array{title: string, task: ?string, location: ?string, from: string, to: string, start: bool}>,
     *         missing: ?array{title: string, task: ?string, location: ?string, from: string, to: string}
     *     }>
     * }
     */
    public function build(
        Department $department,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeZone $tz,
    ): array {
        $shifts = $this->shifts->findForDepartmentBetween($department, $from, $to);

        $users = $this->assignedUsers($shifts);
        $windows = $this->dayWindows($shifts, $tz);
        $unstaffed = $this->unstaffedStaffShifts($shifts);

        $rows = [];
        foreach ($windows as $iso => $window) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, $tz);
            $first = true;
            for ($minute = $window['from']; $minute < $window['to']; $minute += self::SLOT_MINUTES) {
                $slotStart = $day->modify(\sprintf('+%d minutes', $minute));
                $slotEnd = $slotStart->modify(\sprintf('+%d minutes', self::SLOT_MINUTES));

                foreach ($unstaffed as $shift) {
                    if ($this->startsWithin($shift, $slotStart, $slotEnd)) {
                        $rows[] = $this->missingRow($shift, $tz, $day->format('D j M'), $first);
                        $first = false;
                    }
                }

                $rows[] = [
                    'kind' => 'slot',
                    'dayLabel' => $first ? $day->format('D j M') : null,
                    'time' => $slotStart->format('H:i'),
                    'isDayStart' => $first,
                    'cells' => $this->cellsFor($shifts, $slotStart, $slotEnd),
                    'missing' => null,
                ];
                $first = false;
            }
        }

        return ['users' => $users, 'rows' => $rows];
    }

    /**
     * Everyone holding a confirmed assignment in the range, in name order, which is the column
     * order. Somebody with no assignment at all gets no column: an empty one says nothing.
     *
     * @param Shift[] $shifts
     *
     * @return list<User>
     */
    private function assignedUsers(array $shifts): array
    {
        $users = [];
        foreach ($shifts as $shift) {
            foreach ($shift->getEntries() as $entry) {
                if ($entry->getState() === ShiftEntryState::ASSIGNMENT) {
                    $users[$entry->getUser()->getId()] = $entry->getUser();
                }
            }
        }

        $ordered = array_values($users);
        usort($ordered, static fn (User $a, User $b): int => strcasecmp($a->getName(), $b->getName()));

        return $ordered;
    }

    /**
     * The half-hour window to lay out for each day that has anything, from the first shift to the
     * last, rounded outwards to whole hours. Computed from the part of each shift that falls on the
     * day, so a shift running past midnight opens the next day at 00:00 rather than being clipped
     * out of it.
     *
     * @param Shift[] $shifts
     *
     * @return array<string, array{from: int, to: int}> ISO date => minutes from that day's midnight
     */
    private function dayWindows(array $shifts, \DateTimeZone $tz): array
    {
        $windows = [];
        foreach ($shifts as $shift) {
            $start = $shift->getStartsAt()->setTimezone($tz);
            $end = $shift->getEndsAt()->setTimezone($tz);

            for ($day = $start->setTime(0, 0); $day < $end; $day = $day->modify('+1 day')) {
                $fromMinutes = max(0, (int) round(($start->getTimestamp() - $day->getTimestamp()) / 60));
                $toMinutes = min(self::MINUTES_PER_DAY, (int) round(($end->getTimestamp() - $day->getTimestamp()) / 60));
                if ($toMinutes <= $fromMinutes) {
                    continue;
                }

                $iso = $day->format('Y-m-d');
                $openAt = (int) floor($fromMinutes / 60) * 60;
                $closeAt = (int) ceil($toMinutes / 60) * 60;
                $windows[$iso]['from'] = min($windows[$iso]['from'] ?? $openAt, $openAt);
                $windows[$iso]['to'] = max($windows[$iso]['to'] ?? $closeAt, $closeAt);
            }
        }

        ksort($windows);

        return $windows;
    }

    /**
     * Staff shifts with nobody on them. Public shifts are left out on purpose: an empty volunteer
     * shift is a shift still open for sign-up, not a hole in the roster.
     *
     * @param Shift[] $shifts
     *
     * @return list<Shift>
     */
    private function unstaffedStaffShifts(array $shifts): array
    {
        $unstaffed = [];
        foreach ($shifts as $shift) {
            if (!$shift->getAudience()->isStaffOnly()) {
                continue;
            }
            foreach ($shift->getEntries() as $entry) {
                if ($entry->getState() === ShiftEntryState::ASSIGNMENT) {
                    continue 2;
                }
            }
            $unstaffed[] = $shift;
        }

        return $unstaffed;
    }

    /**
     * What each person is doing in this half hour, keyed by user id. `start` marks the slot a shift
     * begins in, which is the only one that carries the detail: repeating it down every slot of a
     * four-hour shift is unreadable.
     *
     * The times travel with it because a shift does not have to sit on the raster. One running
     * 10:15 to 12:45 is drawn from the 10:00 slot to the 12:30 one, and without its own times it
     * would read as starting and ending half an hour out.
     *
     * @param Shift[] $shifts
     *
     * @return array<int, array{title: string, task: ?string, location: ?string, from: string, to: string, start: bool}>
     */
    private function cellsFor(array $shifts, \DateTimeImmutable $slotStart, \DateTimeImmutable $slotEnd): array
    {
        $cells = [];
        foreach ($shifts as $shift) {
            if (!$this->covers($shift, $slotStart, $slotEnd)) {
                continue;
            }
            foreach ($shift->getEntries() as $entry) {
                if ($entry->getState() !== ShiftEntryState::ASSIGNMENT) {
                    continue;
                }
                $cells[$entry->getUser()->getId()] = [
                    'title' => $shift->getTitle(),
                    'task' => $shift->distinctTaskName(),
                    'location' => $shift->getLocation()?->fullName(),
                    'from' => $shift->getStartsAt()->setTimezone($slotStart->getTimezone())->format('H:i'),
                    'to' => $shift->getEndsAt()->setTimezone($slotStart->getTimezone())->format('H:i'),
                    'start' => $this->startsWithin($shift, $slotStart, $slotEnd),
                ];
            }
        }

        return $cells;
    }

    /** @return array<string, mixed> */
    private function missingRow(Shift $shift, \DateTimeZone $tz, string $dayLabel, bool $isDayStart): array
    {
        return [
            'kind' => 'missing',
            'dayLabel' => $isDayStart ? $dayLabel : null,
            'time' => $shift->getStartsAt()->setTimezone($tz)->format('H:i'),
            'isDayStart' => $isDayStart,
            'cells' => [],
            'missing' => [
                'title' => $shift->getTitle(),
                'task' => $shift->distinctTaskName(),
                'location' => $shift->getLocation()?->fullName(),
                'from' => $shift->getStartsAt()->setTimezone($tz)->format('H:i'),
                'to' => $shift->getEndsAt()->setTimezone($tz)->format('H:i'),
            ],
        ];
    }

    private function covers(Shift $shift, \DateTimeImmutable $slotStart, \DateTimeImmutable $slotEnd): bool
    {
        return $shift->getStartsAt() < $slotEnd && $shift->getEndsAt() > $slotStart;
    }

    private function startsWithin(Shift $shift, \DateTimeImmutable $slotStart, \DateTimeImmutable $slotEnd): bool
    {
        return $shift->getStartsAt() >= $slotStart && $shift->getStartsAt() < $slotEnd;
    }
}
