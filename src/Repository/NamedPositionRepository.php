<?php

namespace App\Repository;

use App\Entity\NamedPosition;
use App\Entity\PositionGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NamedPosition>
 */
class NamedPositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NamedPosition::class);
    }

    public function nextDisplayOrder(PositionGroup $group): int
    {
        return 1 + (int) $this->createQueryBuilder('p')
            ->select('COALESCE(MAX(p.displayOrder), 0)')
            ->andWhere('p.group = :group')
            ->setParameter('group', $group)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
