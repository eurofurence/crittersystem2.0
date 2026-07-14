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
     * Entries for a user, ordered by shift start (joins the shift for display).
     *
     * @return ShiftEntry[]
     */
    public function findByUserOrdered(User $user): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.shift', 's')
            ->addSelect('s')
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
     * Whether the user already has an entry for a shift that overlaps the given
     * window (used to prevent double-booking). Optionally excludes one shift.
     */
    public function hasOverlap(User $user, \DateTimeImmutable $start, \DateTimeImmutable $end, ?Shift $exclude = null): bool
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.shift', 's')
            ->andWhere('e.user = :user')
            ->andWhere('s.startsAt < :end')
            ->andWhere('s.endsAt > :start')
            ->setParameter('user', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($exclude !== null) {
            $qb->andWhere('s != :exclude')->setParameter('exclude', $exclude);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
