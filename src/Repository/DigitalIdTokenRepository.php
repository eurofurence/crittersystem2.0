<?php

namespace App\Repository;

use App\Entity\DigitalIdToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DigitalIdToken>
 */
class DigitalIdTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DigitalIdToken::class);
    }

    /** A non-expired token for the user (the most-recently created one), if any. */
    public function findActiveForUser(User $user): ?DigitalIdToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('t.expiresAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActive(string $token): ?DigitalIdToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.token = :token')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Bulk-delete tokens whose expires_at is in the past. Returns rows removed. */
    public function deleteExpired(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
