<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserHoursCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserHoursCache>
 */
class UserHoursCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserHoursCache::class);
    }

    public function findOneByUser(User $user): ?UserHoursCache
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Caches for many users in one query, keyed by user id. Lets a list screen avoid a
     * per-row lookup; users without a cache row are simply absent from the result.
     *
     * @param User[] $users
     *
     * @return array<int, UserHoursCache>
     */
    public function findByUsers(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.user IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$row->getUser()->getId()] = $row;
        }

        return $byUser;
    }
}
