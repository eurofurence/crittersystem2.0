<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\ShiftGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ShiftGroup>
 */
class ShiftGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShiftGroup::class);
    }

    public function findOneByUuid(string $uuid): ?ShiftGroup
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
    }

    /**
     * Every group, department first so the management list reads as one section per department.
     *
     * Joins the members: the list renders each group's shifts, which would otherwise cost a query
     * per row.
     *
     * @return ShiftGroup[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.department', 'd')
            ->addSelect('d')
            ->leftJoin('g.shifts', 's')
            ->addSelect('s')
            ->orderBy('d.name', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->addOrderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return ShiftGroup[] */
    public function findForDepartment(Department $department): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.department = :department')
            ->setParameter('department', $department)
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
