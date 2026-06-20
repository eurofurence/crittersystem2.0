<?php

namespace App\Repository;

use App\Entity\GoodieDistribution;
use App\Entity\GoodieItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoodieDistribution>
 */
class GoodieDistributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoodieDistribution::class);
    }

    /** @return GoodieDistribution[] most recent first */
    public function findRecent(int $limit = 10): array
    {
        return $this->findBy([], ['distributedAt' => 'DESC'], $limit);
    }

    /** @return GoodieDistribution[] a user's distributions, newest first */
    public function findByUser(User $user, int $limit = 20): array
    {
        return $this->findBy(['user' => $user], ['distributedAt' => 'DESC'], $limit);
    }

    /** Total quantity of an item already given to a user (for max-per-person checks). */
    public function quantityForUserAndItem(User $user, GoodieItem $item): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.quantity), 0)')
            ->andWhere('d.user = :user')
            ->andWhere('d.item = :item')
            ->setParameter('user', $user)
            ->setParameter('item', $item)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.distributedAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Total quantity of goodies a user has received (for staff stats). */
    public function totalQuantityForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.quantity), 0)')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
