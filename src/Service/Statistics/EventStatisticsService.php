<?php

namespace App\Service\Statistics;

use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Repository\DutyRecordRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\ShiftRepository;
use App\Repository\UserRepository;
use App\Repository\WorklogRepository;
use App\Service\EventConfigStore;
use App\Service\HoursCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Event-wide totals for the closing statistics dashboard.
 *
 * Computed live on every request; there is no cache table and no scheduled recalculation, because
 * the page is opened by hand a few times per event and a stale figure read out on stage is worse
 * than a slow page. The cost is bounded by the shifts and entries inside the event window rather
 * than by the size of the database.
 *
 * Draft shifts are excluded from every hours and slot figure and counted only as a draft total:
 * nobody can see a draft, so nobody worked one.
 *
 * Overlapping time is merged per person before it is summed. Someone booked on two overlapping
 * shifts, or on duty while working a shift, spent that time once, and an event total that adds it
 * twice overstates the headline number the whole presentation rests on.
 */
final class EventStatisticsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftRepository $shifts,
        private readonly ShiftEntryRepository $entries,
        private readonly UserRepository $users,
        private readonly WorklogRepository $worklogs,
        private readonly DutyRecordRepository $duties,
        private readonly HoursCalculator $hours,
        private readonly EventConfigStore $config,
    ) {
    }

    public function window(): EventWindow
    {
        return EventWindow::fromConfig($this->config);
    }

    public function compute(): EventStatistics
    {
        $window = $this->window();
        $now = new \DateTimeImmutable();

        $shifts = $this->shiftsInWindow($window);
        $published = array_values(array_filter($shifts, static fn (Shift $s): bool => $s->getState()->isPublished()));

        $entries = $this->entriesInWindow($window);
        $this->primeStaffRoles($entries);

        $perUser = $this->groupByUser($entries);
        $duty = $this->dutyIntervalsByUser($window);
        $worklog = $this->worklogHoursByUser($window);

        return new EventStatistics(
            window: $window,
            generatedAt: $now,
            shiftsPublished: \count($published),
            shiftsDraft: \count($shifts) - \count($published),
            shiftsByAudience: $this->countByAudience($published),
            shiftHoursByAudience: $this->hoursByAudience($published),
            shiftsVolunteerAudience: \count(array_filter($published, static fn (Shift $s): bool => $s->getAudience()->isPublic())),
            shiftsStaffAudience: \count(array_filter($published, static fn (Shift $s): bool => !$s->getAudience()->isPublic())),
            shiftHoursScheduled: array_sum(array_map(static fn (Shift $s): float => $s->getDurationHours(), $published)),
            slotsNeeded: $this->slotsNeeded($window),
            slotsFilled: \count(array_filter($entries, static fn (ShiftEntry $e): bool => $e->isAssignment())),
            usersRegistered: $this->users->countAll(),
            usersStaff: $this->countStaffAccounts(),
            usersActive: \count($perUser),
            usersActiveStaff: \count(array_filter($perUser, static fn (array $g): bool => $g['user']->isStaff())),
            usersActiveVolunteer: \count(array_filter($perUser, static fn (array $g): bool => !$g['user']->isStaff())),
            planned: $this->plannedHours($perUser),
            worked: $this->workedHours($perUser, $duty, $worklog, $now, null),
            workedStaff: $this->workedHours($perUser, $duty, $worklog, $now, true),
            workedVolunteer: $this->workedHours($perUser, $duty, $worklog, $now, false),
            dutyHours: $this->totalDutyHours($duty),
            worklogHours: array_sum($worklog),
            entriesTotal: \count($entries),
            entriesAssignment: \count(array_filter($entries, static fn (ShiftEntry $e): bool => $e->isAssignment())),
            entriesApplication: \count(array_filter($entries, static fn (ShiftEntry $e): bool => $e->isApplication())),
            noshows: \count(array_filter($entries, static fn (ShiftEntry $e): bool => $e->isNoshow())),
            departmentsWithShifts: $this->countDistinctDepartments($published),
            locationsUsed: $this->countDistinctLocations($published),
            longestSingleShiftHours: $published === [] ? 0.0 : max(array_map(static fn (Shift $s): float => $s->getDurationHours(), $published)),
            busiestDepartment: $this->busiestDepartment($entries),
        );
    }

    /**
     * Published and draft shifts overlapping the window. A shift that straddles a boundary is
     * counted whole rather than clipped: it is one shift somebody worked, not a fraction of one.
     *
     * @return list<Shift>
     */
    private function shiftsInWindow(EventWindow $window): array
    {
        $qb = $this->shifts->createQueryBuilder('s')
            ->addSelect('d', 'l')
            ->leftJoin('s.department', 'd')
            ->leftJoin('s.location', 'l');

        $this->restrictToWindow($qb, $window, 's');

        return $qb->getQuery()->getResult();
    }

    /**
     * Entries on published shifts overlapping the window, with shift, department and user joined so
     * the grouping and staff split below cost no further queries.
     *
     * The user's one-to-one satellites are joined too. Doctrine fetches every mappedBy one-to-one
     * on User eagerly, so each hydrated user without them costs five further queries, and this
     * query hydrates every person who worked the event.
     *
     * @return list<ShiftEntry>
     */
    private function entriesInWindow(EventWindow $window): array
    {
        $qb = $this->entries->createQueryBuilder('e')
            ->addSelect('s', 'u', 'd', 'pd', 'c', 'st', 'us', 'cons')
            ->join('e.shift', 's')
            ->join('e.user', 'u')
            ->leftJoin('s.department', 'd')
            ->leftJoin('u.personalData', 'pd')
            ->leftJoin('u.contact', 'c')
            ->leftJoin('u.settings', 'st')
            ->leftJoin('u.state', 'us')
            ->leftJoin('u.consent', 'cons')
            ->andWhere('s.state = :published')
            ->setParameter('published', ShiftState::PUBLISHED);

        $this->restrictToWindow($qb, $window, 's');

        return $qb->getQuery()->getResult();
    }

    private function restrictToWindow(QueryBuilder $qb, EventWindow $window, string $alias): void
    {
        if ($window->from !== null) {
            $qb->andWhere(\sprintf('%s.endsAt > :windowFrom', $alias))->setParameter('windowFrom', $window->from);
        }
        if ($window->to !== null) {
            $qb->andWhere(\sprintf('%s.startsAt < :windowTo', $alias))->setParameter('windowTo', $window->to);
        }
    }

    private function slotsNeeded(EventWindow $window): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(n.count), 0)')
            ->from(NeededVolunteerType::class, 'n')
            ->join('n.shift', 's')
            ->andWhere('s.state = :published')
            ->setParameter('published', ShiftState::PUBLISHED);

        $this->restrictToWindow($qb, $window, 's');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Loading every active user's group assignments in one query keeps {@see User::isStaff()} from
     * costing a query per person while the staff split is computed.
     *
     * @param list<ShiftEntry> $entries
     */
    private function primeStaffRoles(array $entries): void
    {
        $users = [];
        foreach ($entries as $entry) {
            $users[$entry->getUser()->getId()] = $entry->getUser();
        }

        if ($users !== []) {
            $this->users->preloadGroupAssignments(array_values($users));
        }
    }

    /**
     * @param list<ShiftEntry> $entries
     *
     * @return array<int, array{user: User, entries: list<ShiftEntry>}>
     */
    private function groupByUser(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $id = $entry->getUser()->getId();
            $grouped[$id] ??= ['user' => $entry->getUser(), 'entries' => []];
            $grouped[$id]['entries'][] = $entry;
        }

        return $grouped;
    }

    /** @param array<int, array{user: User, entries: list<ShiftEntry>}> $perUser */
    private function plannedHours(array $perUser): HoursTotals
    {
        $raw = 0.0;
        $credited = 0.0;

        foreach ($perUser as $group) {
            $raw += $this->unionHours($this->intervalsOf($group['entries']));
            $credited += $this->hours->breakdown($group['entries'])->total();
        }

        return new HoursTotals($raw, $credited);
    }

    /**
     * Hours actually worked: shifts that have ended and were not a no-show, plus on-duty time and
     * manually logged hours.
     *
     * Duty time is merged into the same per-person interval union as the shifts, because a manager
     * on duty during their own shift spent that hour once. Worklogs record an amount without a
     * period, so they can only be added; they are entered by hand for work no shift covered.
     *
     * Credited hours deliberately exclude duty time. The application rewards shifts and worklogs
     * and nothing else, so crediting duty here would print a number no volunteer can find on their
     * own profile.
     *
     * @param array<int, array{user: User, entries: list<ShiftEntry>}> $perUser
     * @param array<int, list<array{0: int, 1: int}>>                  $duty
     * @param array<int, float>                                        $worklog
     * @param bool|null                                                $staffOnly null for everyone, true for staff, false for volunteers
     */
    private function workedHours(array $perUser, array $duty, array $worklog, \DateTimeImmutable $now, ?bool $staffOnly): HoursTotals
    {
        $raw = 0.0;
        $credited = 0.0;

        foreach ($perUser as $id => $group) {
            if ($staffOnly !== null && $group['user']->isStaff() !== $staffOnly) {
                continue;
            }

            $worked = array_values(array_filter(
                $group['entries'],
                static fn (ShiftEntry $e): bool => $e->getShift()->getEndsAt() <= $now,
            ));

            $intervals = array_merge($this->intervalsOf($worked), $duty[$id] ?? []);
            $logged = $worklog[$id] ?? 0.0;

            $raw += $this->unionHours($intervals) + $logged;
            $credited += $this->hours->breakdown($worked)->total() + $logged;
        }

        return new HoursTotals($raw, $credited);
    }

    /**
     * Working intervals of the entries that count towards hours. A no-show contributes no elapsed
     * time; it is a penalty on the credited figure only.
     *
     * @param list<ShiftEntry> $entries
     *
     * @return list<array{0: int, 1: int}>
     */
    private function intervalsOf(array $entries): array
    {
        $intervals = [];
        foreach ($entries as $entry) {
            if ($entry->isNoshow()) {
                continue;
            }
            $intervals[] = [
                $entry->getShift()->getStartsAt()->getTimestamp(),
                $entry->getShift()->getEndsAt()->getTimestamp(),
            ];
        }

        return $intervals;
    }

    /**
     * Total hours covered by a set of periods, counting shared time once.
     *
     * @param list<array{0: int, 1: int}> $intervals
     */
    private function unionHours(array $intervals): float
    {
        if ($intervals === []) {
            return 0.0;
        }

        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $seconds = 0;
        [$openStart, $openEnd] = $intervals[0];

        foreach (\array_slice($intervals, 1) as [$start, $end]) {
            if ($start > $openEnd) {
                $seconds += $openEnd - $openStart;
                [$openStart, $openEnd] = [$start, $end];
                continue;
            }
            $openEnd = max($openEnd, $end);
        }

        return ($seconds + $openEnd - $openStart) / 3600;
    }

    /**
     * On-duty periods per user, clipped to the window so a session left open across the whole event
     * cannot inflate the total.
     *
     * @return array<int, list<array{0: int, 1: int}>>
     */
    private function dutyIntervalsByUser(EventWindow $window): array
    {
        $byUser = [];

        foreach ($this->duties->findPeriodsOverlapping($window->from, $window->to) as $row) {
            [$start, $end] = $this->clipDuty($row['startedAt'], $row['endedAt'], $window);
            if ($end <= $start) {
                continue;
            }
            $byUser[(int) $row['userId']][] = [$start, $end];
        }

        return $byUser;
    }

    /** @param array<int, list<array{0: int, 1: int}>> $duty */
    private function totalDutyHours(array $duty): float
    {
        $total = 0.0;
        foreach ($duty as $intervals) {
            $total += $this->unionHours($intervals);
        }

        return $total;
    }

    /** @return array{0: int, 1: int} */
    private function clipDuty(\DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt, EventWindow $window): array
    {
        $start = $startedAt->getTimestamp();
        $end = ($endedAt ?? new \DateTimeImmutable())->getTimestamp();

        if ($window->from !== null) {
            $start = max($start, $window->from->getTimestamp());
        }
        if ($window->to !== null) {
            $end = min($end, $window->to->getTimestamp());
        }

        return [$start, $end];
    }

    /** @return array<int, float> */
    private function worklogHoursByUser(EventWindow $window): array
    {
        return $this->worklogs->sumHoursByUserBetween($window->from, $window->to);
    }

    private function countStaffAccounts(): int
    {
        return (int) $this->users->createQueryBuilder('u')
            ->select('COUNT(DISTINCT u.id)')
            ->join('u.groupAssignments', 'ga')
            ->join('ga.group', 'g')
            ->andWhere('g.role IN (:roles)')
            ->setParameter('roles', ['ROLE_STAFF', 'ROLE_SUBADMIN', 'ROLE_ADMIN'])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<Shift> $shifts
     *
     * @return array<string, int>
     */
    private function countByAudience(array $shifts): array
    {
        $counts = array_fill_keys(array_map(static fn (ShiftAudience $a): string => $a->value, ShiftAudience::cases()), 0);
        foreach ($shifts as $shift) {
            ++$counts[$shift->getAudience()->value];
        }

        return $counts;
    }

    /**
     * @param list<Shift> $shifts
     *
     * @return array<string, float>
     */
    private function hoursByAudience(array $shifts): array
    {
        $hours = array_fill_keys(array_map(static fn (ShiftAudience $a): string => $a->value, ShiftAudience::cases()), 0.0);
        foreach ($shifts as $shift) {
            $hours[$shift->getAudience()->value] += $shift->getDurationHours();
        }

        return $hours;
    }

    /** @param list<Shift> $shifts */
    private function countDistinctDepartments(array $shifts): int
    {
        $seen = [];
        foreach ($shifts as $shift) {
            $department = $shift->getDepartment();
            if ($department !== null) {
                $seen[$department->getId()] = true;
            }
        }

        return \count($seen);
    }

    /** @param list<Shift> $shifts */
    private function countDistinctLocations(array $shifts): int
    {
        $seen = [];
        foreach ($shifts as $shift) {
            $location = $shift->getLocation();
            if ($location !== null) {
                $seen[$location->getId()] = true;
            }
        }

        return \count($seen);
    }

    /** @param list<ShiftEntry> $entries */
    private function busiestDepartment(array $entries): ?string
    {
        $counts = [];
        foreach ($entries as $entry) {
            $department = $entry->getShift()->getDepartment();
            if ($department === null) {
                continue;
            }
            $name = $department->getName();
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }
}
