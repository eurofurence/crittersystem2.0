<?php

namespace App\Repository;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShiftEntry>
 */
class ShiftEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShiftEntry::class);
    }

    public function findOneByShiftAndUser(Shift $shift, User $user): ?ShiftEntry
    {
        return $this->findOneBy(['shift' => $shift, 'user' => $user]);
    }

    /**
     * Is the user assigned to a shift that is running at the given instant
     * (started and not yet ended)? Drives the automatic "Not available"
     * operational status.
     */
    public function hasActiveShiftAt(User $user, \DateTimeImmutable $at): bool
    {
        return null !== $this->createQueryBuilder('e')
            ->select('1')
            ->join('e.shift', 's')
            ->andWhere('e.user = :user')
            ->andWhere('s.startsAt <= :at')
            ->andWhere('s.endsAt > :at')
            ->setParameter('user', $user)
            ->setParameter('at', $at)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Is the user due at the venue: assigned to a shift starting inside the window, or to one
     * already under way?
     *
     * The second half is what stops somebody arriving late being turned away at the door. A shift
     * that has already ended does not qualify, so the badge cannot be collected the morning after.
     */
    public function hasShiftStartingOrRunningWithin(User $user, \DateTimeImmutable $now, int $windowSeconds): bool
    {
        return null !== $this->createQueryBuilder('e')
            ->select('1')
            ->join('e.shift', 's')
            ->andWhere('e.user = :user')
            ->andWhere('s.startsAt <= :until')
            ->andWhere('s.endsAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->setParameter('until', $now->modify(sprintf('+%d seconds', $windowSeconds)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * One user's shifts overlapping a span, for the security screen's "what are they here for".
     *
     * The shift, its task and its location are joined because the caller names the location by its
     * full path, which walks the parent chain.
     *
     * @return ShiftEntry[]
     */
    public function findForUserBetween(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.shift', 's')->addSelect('s')
            ->leftJoin('s.shiftTask', 't')->addSelect('t')
            ->leftJoin('s.location', 'l')->addSelect('l')
            ->leftJoin('l.parent', 'lp')->addSelect('lp')
            ->andWhere('e.user = :user')
            ->andWhere('s.startsAt < :to')
            ->andWhere('s.endsAt > :from')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The next moment one of this user's shifts starts or ends.
     *
     * Their operational status is derived from the clock, not from a stored value, so it changes on
     * its own when a shift begins or finishes. Nothing happens server-side at that instant and there
     * is therefore nothing to push - the page has to be told in advance when to look again, which is
     * what this answers.
     *
     * Start and end are queried separately. One MIN over a combined condition would return the start
     * of a shift that is already running, hiding a nearer boundary behind it.
     */
    public function findNextBoundaryAfter(User $user, \DateTimeImmutable $after): ?\DateTimeImmutable
    {
        $candidates = [];
        foreach (['startsAt', 'endsAt'] as $column) {
            $value = $this->createQueryBuilder('e')
                ->select(\sprintf('MIN(s.%s)', $column))
                ->join('e.shift', 's')
                ->andWhere('e.user = :user')
                ->andWhere(\sprintf('s.%s > :after', $column))
                ->setParameter('user', $user)
                ->setParameter('after', $after)
                ->getQuery()
                ->getSingleScalarResult();

            if ($value !== null) {
                $candidates[] = $value instanceof \DateTimeImmutable
                    ? $value
                    : new \DateTimeImmutable((string) $value);
            }
        }

        return $candidates === [] ? null : min($candidates);
    }

    /**
     * Entries for a user, ordered by shift start (joins the shift for display).
     *
     * The shift group and its other members are joined because "my shifts" renders a grouped
     * commitment as one block, which otherwise costs two queries per row. The location and its two
     * ancestors are joined for the same reason: every caller names it with Location::fullName(),
     * which walks the parent chain and otherwise costs a query per ancestor per row.
     *
     * @return ShiftEntry[]
     */
    public function findByUserOrdered(User $user): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.shift', 's')
            ->addSelect('s')
            ->leftJoin('s.shiftGroup', 'grp')
            ->addSelect('grp')
            ->leftJoin('grp.shifts', 'grpShifts')
            ->addSelect('grpShifts')
            ->leftJoin('s.shiftTask', 'task')
            ->addSelect('task')
            ->leftJoin('s.location', 'loc')
            ->addSelect('loc')
            ->leftJoin('loc.parent', 'locParent')
            ->addSelect('locParent')
            ->leftJoin('locParent.parent', 'locGrandparent')
            ->addSelect('locGrandparent')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count the user's no-show shifts whose shift starts at/after the given
     * baseline. A null baseline counts every no-show.
     */
    public function countNoShowsSince(User $user, ?\DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.shift', 's')
            ->andWhere('e.user = :user')
            ->andWhere('e.noshow = true')
            ->setParameter('user', $user);

        if ($since !== null) {
            $qb->andWhere('s.startsAt >= :since')->setParameter('since', $since);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findNextUpcoming(User $user, \DateTimeImmutable $now): ?ShiftEntry
    {
        return $this->createQueryBuilder('e')
            ->join('e.shift', 's')
            ->addSelect('s')
            ->andWhere('e.user = :user')
            ->andWhere('s.startsAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('s.startsAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForShift(Shift $shift): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.shift = :shift')
            ->setParameter('shift', $shift)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForShiftAndType(Shift $shift, VolunteerType $type): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.shift = :shift')
            ->andWhere('e.volunteerType = :type')
            ->setParameter('shift', $shift)
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many volunteers are booked on each shift, per volunteer type, in one query.
     *
     * @param Shift[] $shifts
     *
     * @return array<int, array<int, int>> shift id => volunteer type id => count
     */
    public function assignedCountsForShifts(array $shifts): array
    {
        $shifts = array_values(array_filter($shifts, static fn (Shift $s): bool => $s->getId() !== null));
        if ($shifts === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.shift) AS shiftId', 'IDENTITY(e.volunteerType) AS typeId', 'COUNT(e.id) AS total')
            ->andWhere('e.shift IN (:shifts)')
            ->setParameter('shifts', $shifts)
            ->groupBy('e.shift', 'e.volunteerType')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['shiftId']][(int) $row['typeId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Every entry on the given shifts, with the user and volunteer type joined.
     *
     * The board reads the assignee, the role and the check-in state of each row, so hydrating them
     * lazily would cost two queries per assignment on a day with a hundred of them.
     *
     * The user's one-to-one satellites are joined as well: Doctrine fetches every mappedBy one-to-one
     * on User eagerly, so each hydrated user without them costs five further queries.
     *
     * @param Shift[] $shifts
     *
     * @return array<int, list<ShiftEntry>> shift id => entries
     */
    public function findForShifts(array $shifts): array
    {
        $shifts = array_values(array_filter($shifts, static fn (Shift $s): bool => $s->getId() !== null));
        if ($shifts === []) {
            return [];
        }

        /** @var ShiftEntry[] $entries */
        $entries = $this->createQueryBuilder('e')
            ->join('e.user', 'u')->addSelect('u')
            ->join('e.volunteerType', 't')->addSelect('t')
            ->leftJoin('u.personalData', 'pd')->addSelect('pd')
            ->leftJoin('u.contact', 'c')->addSelect('c')
            ->leftJoin('u.settings', 'st')->addSelect('st')
            ->leftJoin('u.state', 'us')->addSelect('us')
            ->leftJoin('u.consent', 'cons')->addSelect('cons')
            ->andWhere('e.shift IN (:shifts)')
            ->setParameter('shifts', $shifts)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byShift = [];
        foreach ($entries as $entry) {
            $byShift[$entry->getShift()->getId()][] = $entry;
        }

        return $byShift;
    }

    /**
     * Every entry belonging to any of the given users, with the shift joined, keyed by user id.
     *
     * Feeds the credited-hours totals for a whole page of volunteers at once: computing them one
     * user at a time means a query each, which on a full staff list is the difference between one
     * query and fifty.
     *
     * @param User[] $users
     *
     * @return array<int, list<ShiftEntry>> user id => entries
     */
    public function findByUsers(array $users): array
    {
        $users = array_values(array_filter($users, static fn (User $u): bool => $u->getId() !== null));
        if ($users === []) {
            return [];
        }

        /** @var ShiftEntry[] $entries */
        $entries = $this->createQueryBuilder('e')
            ->join('e.shift', 's')->addSelect('s')
            ->andWhere('e.user IN (:users)')
            ->setParameter('users', $users)
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();

        $byUser = [];
        foreach ($entries as $entry) {
            $byUser[$entry->getUser()->getId()][] = $entry;
        }

        return $byUser;
    }

    /**
     * The user's own entries on the given shifts, keyed by shift id.
     *
     * @param Shift[] $shifts
     *
     * @return array<int, ShiftEntry>
     */
    public function findByUserAndShifts(User $user, array $shifts): array
    {
        $shifts = array_values(array_filter($shifts, static fn (Shift $s): bool => $s->getId() !== null));
        if ($shifts === []) {
            return [];
        }

        $entries = $this->createQueryBuilder('e')
            ->andWhere('e.user = :user')
            ->andWhere('e.shift IN (:shifts)')
            ->setParameter('user', $user)
            ->setParameter('shifts', $shifts)
            ->getQuery()
            ->getResult();

        $byShift = [];
        foreach ($entries as $entry) {
            $byShift[$entry->getShift()->getId()] = $entry;
        }

        return $byShift;
    }

    /**
     * Every window the user is already booked for, as [start, end] pairs.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int}> start, end, shift id
     */
    public function bookedIntervals(User $user): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('s.startsAt AS startsAt', 's.endsAt AS endsAt', 's.id AS shiftId')
            ->join('e.shift', 's')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [$row['startsAt'], $row['endsAt'], (int) $row['shiftId']],
            $rows,
        );
    }

    /**
     * Whether the user already has an entry for a shift that overlaps the given
     * window (used to prevent double-booking).
     *
     * @param Shift[] $exclude shifts that must not count as an overlap. More than one is needed for
     *                         shift groups: the members are taken together, so a member overlapping
     *                         another member of the same group must not refuse the application. An
     *                         overlap with any shift outside the list still refuses.
     */
    public function hasOverlap(User $user, \DateTimeImmutable $start, \DateTimeImmutable $end, array $exclude = []): bool
    {
        $exclude = array_values(array_filter($exclude, static fn (?Shift $s): bool => $s?->getId() !== null));

        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.shift', 's')
            ->andWhere('e.user = :user')
            ->andWhere('s.startsAt < :end')
            ->andWhere('s.endsAt > :start')
            ->setParameter('user', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($exclude !== []) {
            $qb->andWhere('s NOT IN (:exclude)')->setParameter('exclude', $exclude);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
