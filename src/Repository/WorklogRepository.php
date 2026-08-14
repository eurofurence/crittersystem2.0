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

    /**
     * Manual hours for several users at once, keyed by user id. Users with no worklog are absent.
     *
     * @param User[] $users
     *
     * @return array<int, float>
     */
    public function sumHoursForUsers(array $users): array
    {
        $users = array_values(array_filter($users, static fn (User $u): bool => $u->getId() !== null));
        if ($users === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('w')
            ->select('IDENTITY(w.user) AS userId', 'COALESCE(SUM(w.hours), 0) AS total')
            ->andWhere('w.user IN (:users)')
            ->setParameter('users', $users)
            ->groupBy('w.user')
            ->getQuery()
            ->getResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['userId']] = (float) $row['total'];
        }

        return $totals;
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
