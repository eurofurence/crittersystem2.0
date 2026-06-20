<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Worklog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Worklog>
 */
class WorklogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Worklog::class);
    }

    /** @return Worklog[] */
    public function findByUserOrdered(User $user): array
    {
        return $this->findBy(['user' => $user], ['workedAt' => 'DESC']);
    }

    /** @return Worklog[] most recent first */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['workedAt' => 'DESC']);
    }

    public function sumHoursForUser(User $user): float
    {
        return (float) $this->createQueryBuilder('w')
            ->select('COALESCE(SUM(w.hours), 0)')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
