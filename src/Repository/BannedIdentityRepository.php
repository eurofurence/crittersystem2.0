<?php

namespace App\Repository;

use App\Entity\BannedIdentity;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BannedIdentity>
 */
class BannedIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BannedIdentity::class);
    }

    public function findOneByHash(string $hash): ?BannedIdentity
    {
        return $this->findOneBy(['hash' => $hash]);
    }

    /** @return BannedIdentity[] */
    public function findWithAppeals(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.appealRequestedAt IS NOT NULL')
            ->orderBy('b.appealRequestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return BannedIdentity[] */
    public function findRecentAll(): array
    {
        return $this->createQueryBuilder('b')
            ->orderBy('b.bannedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return BannedIdentity[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.bannedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
