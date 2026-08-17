<?php

namespace App\Repository;

use App\Entity\OperationalStatusOverride;
use App\Entity\User;
use App\Service\OperationalStatusService;
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

    /**
     * Users currently marked "Free to help".
     *
     * This is the candidate pool for a help call: being free to help is a precondition of
     * eligibility, so nobody outside this set can answer one. It bounds the fan-out to the handful
     * of people actually available rather than the whole event.
     *
     * The query is rooted at User because Doctrine cannot return an entity reached only through a
     * join.
     *
     * @return User[]
     */
    public function findFreeToHelpUsers(?\DateTimeImmutable $now = null): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->join(OperationalStatusOverride::class, 'o', 'WITH', 'o.user = u')
            ->andWhere('o.value = :freeToHelp')
            ->andWhere('o.expiresAt IS NULL OR o.expiresAt > :now')
            ->setParameter('freeToHelp', OperationalStatusService::FREE_TO_HELP)
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
