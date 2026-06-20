<?php

namespace App\Repository;

use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Shift>
 */
class ShiftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shift::class);
    }

    /** @return Shift[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['startsAt' => 'ASC']);
    }

    /** @return Shift[] Shifts that have not yet ended, soonest first. */
    public function findUpcoming(?\DateTimeImmutable $from = null): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.endsAt >= :now')
            ->setParameter('now', $from ?? new \DateTimeImmutable())
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Shift[] shifts that start on the given calendar day, with optional filters */
    public function findForDay(\DateTimeImmutable $day, ?Location $location = null, ?ShiftType $shiftType = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.startsAt >= :from AND s.startsAt < :to')
            ->setParameter('from', $day->setTime(0, 0))
            ->setParameter('to', $day->setTime(0, 0)->modify('+1 day'))
            ->orderBy('s.startsAt', 'ASC');

        if ($location !== null) {
            $qb->andWhere('s.location = :location')->setParameter('location', $location);
        }
        if ($shiftType !== null) {
            $qb->andWhere('s.shiftType = :shiftType')->setParameter('shiftType', $shiftType);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Distinct calendar days (Y-m-d) that have at least one upcoming shift,
     * for the date selector.
     *
     * @return string[]
     */
    public function findUpcomingDays(int $limit = 30): array
    {
        /** @var array<int, array{d: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.startsAt AS d')
            ->andWhere('s.endsAt >= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();

        $days = [];
        foreach ($rows as $row) {
            $days[$row['d']->format('Y-m-d')] = true;
        }

        return \array_slice(array_keys($days), 0, $limit);
    }
}
