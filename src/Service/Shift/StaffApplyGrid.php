<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\User;
use App\Repository\ShiftRepository;
use App\Repository\UserGroupAssignmentRepository;
use App\Service\Availability\AvailabilityService;
use App\Service\Assignment\EventHoursGuard;
use App\Service\DisplaySettings;
use App\Service\ShiftSignupService;

/**
 * The staff shift application screen: one day at a time, a column per department, shifts placed
 * against a shared time axis.
 *
 * Everything is read in a fixed number of queries. The screen it replaces asked the database once
 * per department for the full shift list and then once per row for staffing, eligibility, hours and
 * availability, which is why it took long enough to be reported as broken.
 */
final class StaffApplyGrid
{
    /** Shown when the selected day has no shifts at all, so the axis still has a scale. */
    private const EMPTY_WINDOW = [8 * 60, 20 * 60];

    public function __construct(
        private readonly ShiftRepository $shifts,
        private readonly UserGroupAssignmentRepository $memberships,
        private readonly ShiftVisibilityResolver $visibility,
        private readonly ShiftEligibility $eligibility,
        private readonly ShiftSignupService $signup,
        private readonly ShiftGroupResolver $groups,
        private readonly AvailabilityService $availability,
        private readonly EventHoursGuard $hoursGuard,
        private readonly DisplaySettings $display,
        private readonly LaneLayout $lanes,
    ) {
    }

    /**
     * The eligibility preload lives exactly as long as the columns it is read for: it holds
     * entities, and leaving them behind hands the next thing that runs a detached volunteer type.
     *
     * @param string[] $departmentUuids the departments the manager narrowed the grid to; empty means
     *                                  every department the other filters leave
     *
     * @return array{
     *     days: list<array{iso: string, label: string, count: int}>,
     *     day: ?string,
     *     departments: list<array{department: Department, member: bool, count: int, selected: bool}>,
     *     columns: list<array{department: Department, lanes: int, blocks: list<array<string, mixed>>}>,
     *     hours: list<array{label: string, top: float}>,
     *     availability: list<array{top: float, height: float, label: string, value: string}>,
     *     windowStart: int,
     *     windowEnd: int,
     *     mineOnly: bool,
     *     planned: float,
     *     recommendedMax: int,
     *     overHours: float
     * }
     */
    public function build(User $user, ?string $day, bool $mineOnly, array $departmentUuids): array
    {
        $tz = $this->display->timezone();
        $visible = $this->visibility->filterVisible($this->shifts->findUpcomingStaffPublished(), $user);

        $byDay = [];
        foreach ($visible as $shift) {
            $byDay[$shift->getStartsAt()->setTimezone($tz)->format('Y-m-d')][] = $shift;
        }
        ksort($byDay);

        $days = [];
        foreach ($byDay as $iso => $shifts) {
            $days[] = [
                'iso' => $iso,
                'label' => \DateTimeImmutable::createFromFormat('!Y-m-d', $iso, $tz)->format('D j M'),
                'count' => \count($shifts),
            ];
        }

        $selectedDay = isset($byDay[$day]) ? $day : ($days[0]['iso'] ?? null);
        $dayShifts = $selectedDay === null ? [] : $byDay[$selectedDay];

        $memberDepartments = [];
        foreach ($this->memberships->findActiveDepartmentsForUser($user) as $department) {
            $memberDepartments[$department->getId()] = true;
        }

        $departments = $this->departmentOptions($dayShifts, $memberDepartments, $mineOnly, $departmentUuids);
        $chosen = [];
        foreach ($departments as $option) {
            if ($option['selected']) {
                $chosen[$option['department']->getId()] = $option['department'];
            }
        }

        $shown = array_values(array_filter(
            $dayShifts,
            static fn (Shift $shift): bool => isset($chosen[$shift->getDepartment()?->getId()]),
        ));
        $midnight = $selectedDay === null
            ? new \DateTimeImmutable('today', $tz)
            : \DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDay, $tz);

        [$windowStart, $windowEnd] = $this->window($shown, $midnight, $tz);

        $this->eligibility->warmUp($user, $shown);
        try {
            $columns = [];
            foreach ($chosen as $department) {
                $columns[] = $this->column($department, $shown, $user, $midnight, $tz, $windowStart, $windowEnd);
            }
        } finally {
            $this->eligibility->coolDown();
        }

        return [
            'days' => $days,
            'day' => $selectedDay,
            'departments' => $departments,
            'columns' => $columns,
            'hours' => $this->hourMarks($windowStart, $windowEnd),
            'availability' => $this->availabilityBands($user, $midnight, $windowStart, $windowEnd),
            'windowStart' => $windowStart,
            'windowEnd' => $windowEnd,
            'mineOnly' => $mineOnly,
            'planned' => $this->hoursGuard->plannedHours($user),
            'recommendedMax' => $this->hoursGuard->recommendedMax(),
            'overHours' => $this->hoursGuard->overBy($user),
        ];
    }

    /**
     * The departments offered in the picker: only those actually running something on the selected
     * day, narrowed to the volunteer's own when they asked for that. An explicit pick that the
     * filters no longer offer is dropped rather than shown as an empty column.
     *
     * @param Shift[]         $dayShifts
     * @param array<int,true> $memberDepartments
     * @param string[]        $departmentUuids
     *
     * @return list<array{department: Department, member: bool, count: int, selected: bool}>
     */
    private function departmentOptions(array $dayShifts, array $memberDepartments, bool $mineOnly, array $departmentUuids): array
    {
        $counts = [];
        $departments = [];
        foreach ($dayShifts as $shift) {
            $department = $shift->getDepartment();
            if ($department === null) {
                continue;
            }
            $departments[$department->getId()] = $department;
            $counts[$department->getId()] = ($counts[$department->getId()] ?? 0) + 1;
        }

        $picked = array_flip($departmentUuids);
        $options = [];
        foreach ($departments as $id => $department) {
            $member = isset($memberDepartments[$id]);
            if ($mineOnly && !$member) {
                continue;
            }
            $options[] = [
                'department' => $department,
                'member' => $member,
                'count' => $counts[$id],
                'selected' => $picked === [] || isset($picked[(string) $department->getUuid()]),
            ];
        }

        usort($options, static fn (array $a, array $b): int => [$b['member'], $a['department']->getName()]
            <=> [$a['member'], $b['department']->getName()]);

        return $options;
    }

    /**
     * The visible time span: from the first shift of the day to the last, rounded out to whole
     * hours. An overnight shift runs past midnight rather than being clipped there, so the axis can
     * read 22:00, 23:00, 00:00, 01:00 and the block stays in one piece.
     *
     * @param Shift[] $shifts
     *
     * @return array{0: int, 1: int} minutes from the selected day's midnight
     */
    private function window(array $shifts, \DateTimeImmutable $midnight, \DateTimeZone $tz): array
    {
        if ($shifts === []) {
            return self::EMPTY_WINDOW;
        }

        $start = null;
        $end = null;
        foreach ($shifts as $shift) {
            $from = $this->minutesFrom($midnight, $shift->getStartsAt(), $tz);
            $to = $this->minutesFrom($midnight, $shift->getEndsAt(), $tz);
            $start = $start === null ? $from : min($start, $from);
            $end = $end === null ? $to : max($end, $to);
        }

        $start = (int) (floor($start / 60) * 60);
        $end = (int) (ceil($end / 60) * 60);

        return [$start, max($end, $start + 60)];
    }

    private function minutesFrom(\DateTimeImmutable $midnight, \DateTimeImmutable $moment, \DateTimeZone $tz): int
    {
        return (int) round(($moment->getTimestamp() - $midnight->getTimestamp()) / 60);
    }

    /**
     * @param Shift[] $shifts
     *
     * @return array{department: Department, lanes: int, blocks: list<array<string, mixed>>}
     */
    private function column(
        Department $department,
        array $shifts,
        User $user,
        \DateTimeImmutable $midnight,
        \DateTimeZone $tz,
        int $windowStart,
        int $windowEnd,
    ): array {
        $span = max(1, $windowEnd - $windowStart);
        $items = [];
        foreach ($shifts as $shift) {
            if ($shift->getDepartment() !== $department) {
                continue;
            }
            $startMin = $this->minutesFrom($midnight, $shift->getStartsAt(), $tz);
            $endMin = $this->minutesFrom($midnight, $shift->getEndsAt(), $tz);
            $items[] = [
                'shift' => $shift,
                'startMin' => $startMin,
                'endMin' => max($startMin + 1, $endMin),
            ];
        }

        usort($items, static fn (array $a, array $b): int => [$a['startMin'], -$a['endMin'], $a['shift']->getId() ?? 0]
            <=> [$b['startMin'], -$b['endMin'], $b['shift']->getId() ?? 0]);

        [$placed, $lanes] = $this->lanes->assign($items);
        $width = round(100 / $lanes, 4);

        $blocks = [];
        foreach ($placed as $item) {
            $shift = $item['shift'];
            $staffing = $this->staffing($shift);
            $status = $this->signup->eligibilityStatus($shift, $user);
            $blocks[] = [
                'shift' => $shift,
                'top' => round(($item['startMin'] - $windowStart) / $span * 100, 4),
                'height' => round(($item['endMin'] - $item['startMin']) / $span * 100, 4),
                'left' => round($item['lane'] * $width, 4),
                'width' => $width,
                'lanes' => $lanes,
                'status' => $status,
                'mine' => $status === 'signed_up',
                'needed' => $staffing['needed'],
                'assigned' => $staffing['assigned'],
                'groupSize' => \count($this->groups->membersFor($shift)),
            ];
        }

        return ['department' => $department, 'lanes' => $lanes, 'blocks' => $blocks];
    }

    /** @return array{needed: int, assigned: int} */
    private function staffing(Shift $shift): array
    {
        $rows = $this->eligibility->availability($shift);

        return [
            'needed' => array_sum(array_column($rows, 'needed')),
            'assigned' => array_sum(array_column($rows, 'assigned')),
        ];
    }

    /** @return list<array{label: string, top: float}> */
    private function hourMarks(int $windowStart, int $windowEnd): array
    {
        $span = max(1, $windowEnd - $windowStart);
        $marks = [];
        for ($minute = $windowStart; $minute <= $windowEnd; $minute += 60) {
            $marks[] = [
                'label' => \sprintf('%02d:00', (int) (($minute / 60) % 24)),
                'top' => round(($minute - $windowStart) / $span * 100, 4),
            ];
        }

        return $marks;
    }

    /**
     * The volunteer's own declared availability, drawn on the time rail so they can see at a glance
     * which shifts fall inside the hours they said they could work.
     *
     * @return list<array{top: float, height: float, label: string, value: string}>
     */
    private function availabilityBands(User $user, \DateTimeImmutable $midnight, int $windowStart, int $windowEnd): array
    {
        $span = max(1, $windowEnd - $windowStart);
        $tz = $midnight->getTimezone();
        $bands = [];

        foreach ($this->availability->rangesForUser($user) as $range) {
            $from = $this->minutesFrom($midnight, $range->getStartsAt(), $tz);
            $to = $this->minutesFrom($midnight, $range->getEndsAt(), $tz);
            $from = max($from, $windowStart);
            $to = min($to, $windowEnd);
            if ($to <= $from) {
                continue;
            }
            $value = $range->getValue();
            $bands[] = [
                'top' => round(($from - $windowStart) / $span * 100, 4),
                'height' => round(($to - $from) / $span * 100, 4),
                'label' => $value->label(),
                'value' => $value->value,
            ];
        }

        return $bands;
    }
}
