<?php

namespace App\Repository;

use App\Entity\OperationalStatusOverride;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OperationalStatusOverride>
 */
class OperationalStatusOverrideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OperationalStatusOverride::class);
    }

    public function findOneByUser(User $user): ?OperationalStatusOverride
    {
        return $this->findOneBy(['user' => $user]);
    }
}
