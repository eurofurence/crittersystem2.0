<?php

namespace App\Repository;

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
