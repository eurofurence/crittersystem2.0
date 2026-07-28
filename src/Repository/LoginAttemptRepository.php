<?php

namespace App\Repository;

use App\Entity\LoginAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginAttempt>
 */
class LoginAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginAttempt::class);
    }

    public function countForUsernameSince(string $usernameKey, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.usernameKey = :username')
            ->andWhere('a.attemptedAt >= :since')
            ->setParameter('username', $usernameKey)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** How many distinct clients attacked this username in the window - one source is a forgetful user. */
    public function countDistinctSourcesForUsernameSince(string $usernameKey, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.ipHash)')
            ->where('a.usernameKey = :username')
            ->andWhere('a.attemptedAt >= :since')
            ->setParameter('username', $usernameKey)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForIpSince(string $ipHash, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.ipHash = :ip')
            ->andWhere('a.attemptedAt >= :since')
            ->setParameter('ip', $ipHash)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteForIp(string $ipHash): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.ipHash = :ip')
            ->setParameter('ip', $ipHash)
            ->getQuery()
            ->execute();
    }

    public function deleteForUsername(string $usernameKey): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.usernameKey = :username')
            ->setParameter('username', $usernameKey)
            ->getQuery()
            ->execute();
    }

    /** Attempts older than the counting window can never influence a decision again. */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.attemptedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
