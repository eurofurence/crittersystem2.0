<?php

namespace App\Repository;

use App\Entity\GoodieDistribution;
use App\Entity\GoodieItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Every count and total here ignores revoked handovers. A revoke is how the desk undoes a mistake,
 * so a revoked row must stop counting towards per-person limits, the volunteer's own goodie list
 * and the desk statistics alike; only {@see findByUser()} can be asked to include them, for the
 * history the desk corrects from.
 *
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
        return $this->createQueryBuilder('d')
            ->andWhere('d.revokedAt IS NULL')
            ->orderBy('d.distributedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param bool $includeRevoked pass true only where the correction itself has to be visible
     *
     * @return GoodieDistribution[] a user's distributions, newest first
     */
    public function findByUser(User $user, int $limit = 20, bool $includeRevoked = false): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.distributedAt', 'DESC')
            ->setMaxResults($limit);

        if (!$includeRevoked) {
            $qb->andWhere('d.revokedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByUuid(string $uuid): ?GoodieDistribution
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
    }

    /** Total quantity of an item already given to a user (for max-per-person checks). */
    public function quantityForUserAndItem(User $user, GoodieItem $item): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.quantity), 0)')
            ->andWhere('d.user = :user')
            ->andWhere('d.item = :item')
            ->andWhere('d.revokedAt IS NULL')
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
            ->andWhere('d.revokedAt IS NULL')
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
            ->andWhere('d.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
