<?php

namespace App\Repository;

use App\Entity\PlanningAvailability;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningAvailability>
 */
class PlanningAvailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningAvailability::class);
    }

    public function findOneByUser(User $user): ?PlanningAvailability
    {
        return $this->findOneBy(['user' => $user]);
    }
}
