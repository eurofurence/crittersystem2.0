<?php

namespace App\Repository;

use App\Entity\AvailabilityRange;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AvailabilityRange>
 */
class AvailabilityRangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvailabilityRange::class);
    }

    /** @return AvailabilityRange[] the user's declared ranges, in time order */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.availability', 'a')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
