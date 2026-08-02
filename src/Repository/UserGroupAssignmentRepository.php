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
     * The user's one-to-one profile relations are joined in deliberately. They are all inverse
     * sides, which Doctrine cannot proxy: it resolves each one with its own query the moment a
     * User is hydrated, whether or not anything reads it. Left as lazy, loading a 200-member
     * department cost 1000 extra queries before a single row was rendered. All five are to-one,
     * so joining them adds columns but no rows.
     *
     * @return UserGroupAssignment[]
     */
    public function findActiveByDepartment(Department $department): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.group', 'g')->addSelect('g')
            ->join('a.user', 'u')->addSelect('u')
            ->leftJoin('u.personalData', 'pd')->addSelect('pd')
            ->leftJoin('u.contact', 'c')->addSelect('c')
            ->leftJoin('u.settings', 's')->addSelect('s')
            ->leftJoin('u.state', 'st')->addSelect('st')
            ->leftJoin('u.consent', 'cons')->addSelect('cons')
            ->andWhere('a.department = :department')
            ->andWhere('a.expiresAt IS NULL OR a.expiresAt > :now')
            ->orderBy('u.name', 'ASC')
            ->setParameter('department', $department)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Departments the user currently belongs to, distinct.
     *
     * Membership in a department is an active department-scoped assignment, whatever the group -
     * the same definition {@see userIsMember()} and {@see \App\Service\DepartmentMemberService} use.
     *
     * @return Department[]
     */
    public function findActiveDepartmentsForUser(\App\Entity\User $user): array
    {
        // Rooted at Department so the query can select it; an inner join means an unscoped
        // assignment (department IS NULL) contributes nothing here. Event-wide grants are resolved
        // separately, see App\Mercure\TopicBuilder.
        return $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT d')
            ->from(Department::class, 'd')
            ->join(UserGroupAssignment::class, 'a', 'WITH', 'a.department = d')
            ->andWhere('a.user = :user')
            ->andWhere('a.expiresAt IS NULL OR a.expiresAt > :now')
            ->orderBy('d.name', 'ASC')
            ->setParameter('user', $user)
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
