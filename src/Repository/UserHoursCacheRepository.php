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
}
