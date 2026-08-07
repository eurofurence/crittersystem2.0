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
     * The next moment one of this user's shifts starts or ends.
     *
     * Their operational status is derived from the clock, not from a stored value, so it changes on
     * its own when a shift begins or finishes. Nothing happens server-side at that instant and there
     * is therefore nothing to push - the page has to be told in advance when to look again, which is
     * what this answers.
     */
    public function findNextBoundaryAfter(User $user, \DateTimeImmutable $after): ?\DateTimeImmutable
    {
        // Each side is filtered on its own column. Taking one MIN over a combined condition would
        // return the start of a shift that is already running, hiding a nearer boundary behind it.
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
     * @return ShiftEntry[]
     */
    public function findByUserOrdered(User $user): array
    {
        // The group and its other members come along: "my shifts" renders a grouped commitment as
        // one block, which would otherwise cost two queries per row.
        return $this->createQueryBuilder('e')
            ->join('e.shift', 's')
            ->addSelect('s')
            ->leftJoin('s.shiftGroup', 'grp')
            ->addSelect('grp')
            ->leftJoin('grp.shifts', 'grpShifts')
            ->addSelect('grpShifts')
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
