<?php

namespace App\Repository;

use App\Entity\ShiftEntry;
use App\Entity\ShiftPosition;
use App\Entity\ShiftPositionAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShiftPositionAssignment>
 */
class ShiftPositionAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShiftPositionAssignment::class);
    }

    public function countForPosition(ShiftPosition $position): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.shiftPosition = :position')
            ->setParameter('position', $position)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByEntryAndPosition(ShiftEntry $entry, ShiftPosition $position): ?ShiftPositionAssignment
    {
        return $this->findOneBy(['shiftEntry' => $entry, 'shiftPosition' => $position]);
    }
}
