<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\DutyRecord;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DutyRecord>
 */
class DutyRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutyRecord::class);
    }

    public function findActiveForUser(User $user): ?DutyRecord
    {
        return $this->findOneBy(['user' => $user, 'endedAt' => null]);
    }

    /** @return DutyRecord[] all currently-open duties, with user + department joined */
    public function findActive(): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.department', 'dep')->addSelect('dep')
            ->join('d.user', 'u')->addSelect('u')
            ->andWhere('d.endedAt IS NULL')
            ->orderBy('d.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Duty sessions in one department that were open at any point in [$from, $to), with the user
     * joined. An open session counts whenever it started before $to, since it has not ended yet.
     *
     * The user's one-to-one satellites are joined too: Doctrine fetches every mappedBy one-to-one on
     * User eagerly, so each hydrated user without them costs five further queries.
     *
     * @return DutyRecord[] oldest first
     */
    public function findForDepartmentOverlapping(Department $department, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.department', 'dep')->addSelect('dep')
            ->join('d.user', 'u')->addSelect('u')
            ->leftJoin('u.personalData', 'pd')->addSelect('pd')
            ->leftJoin('u.contact', 'c')->addSelect('c')
            ->leftJoin('u.settings', 'st')->addSelect('st')
            ->leftJoin('u.state', 'us')->addSelect('us')
            ->leftJoin('u.consent', 'cons')->addSelect('cons')
            ->andWhere('d.department = :department')
            ->andWhere('d.startedAt < :to')
            ->andWhere('d.endedAt IS NULL OR d.endedAt > :from')
            ->setParameter('department', $department)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('d.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return DutyRecord[] a user's duty history, newest first */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.department', 'dep')->addSelect('dep')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
