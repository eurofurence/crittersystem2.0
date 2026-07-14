<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\UserGroupAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserGroupAssignment>
 */
class UserGroupAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserGroupAssignment::class);
    }

    /**
     * Non-expired assignments scoped to a department (its membership).
     *
     * @return UserGroupAssignment[]
     */
    public function findActiveByDepartment(Department $department): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.group', 'g')->addSelect('g')
            ->join('a.user', 'u')->addSelect('u')
            ->andWhere('a.department = :department')
            ->andWhere('a.expiresAt IS NULL OR a.expiresAt > :now')
            ->setParameter('department', $department)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    public function userIsMember(\App\Entity\User $user, Department $department): bool
    {
        return null !== $this->createQueryBuilder('a')
            ->select('1')
            ->andWhere('a.user = :user')
            ->andWhere('a.department = :department')
            ->andWhere('a.expiresAt IS NULL OR a.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('department', $department)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
