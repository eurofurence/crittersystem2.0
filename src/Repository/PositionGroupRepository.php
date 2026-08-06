<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\PositionGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PositionGroup>
 */
class PositionGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositionGroup::class);
    }

    public function findOneByUuid(string $uuid): ?PositionGroup
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
    }

    /** @return PositionGroup[] the department's groups, in display order */
    public function findForDepartment(Department $department): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.department = :department')
            ->setParameter('department', $department)
            ->orderBy('g.displayOrder', 'ASC')
            ->addOrderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nextDisplayOrder(Department $department): int
    {
        return 1 + (int) $this->createQueryBuilder('g')
            ->select('COALESCE(MAX(g.displayOrder), 0)')
            ->andWhere('g.department = :department')
            ->setParameter('department', $department)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
