<?php

namespace App\Repository;

use App\Entity\LoginLockout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LoginLockout>
 */
class LoginLockoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginLockout::class);
    }

    public function findOneFor(string $scope, string $subject): ?LoginLockout
    {
        return $this->findOneBy(['scope' => $scope, 'subject' => $subject]);
    }

    /**
     * Lockouts still running, newest first.
     *
     * @return LoginLockout[]
     */
    public function findActive(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.lockedUntil > :now')
            ->setParameter('now', $now)
            ->orderBy('l.lockedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Creates the lockout, or renews the one that is already there.
     *
     * Written as a single upsert rather than an ORM persist because two failed logins for the same
     * subject can cross the threshold concurrently. A plain INSERT would then raise a unique
     * violation, and Doctrine closes the EntityManager on a failed flush - turning a blocked login
     * attempt into a 500. ON CONFLICT settles the race in the database instead.
     */
    public function insertOrExtend(
        string $scope,
        string $subject,
        Uuid $uuid,
        \DateTimeImmutable $lockedAt,
        \DateTimeImmutable $lockedUntil,
        int $failureCount,
        int $sourceCount,
    ): void {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO login_lockouts (uuid, scope, subject, locked_at, locked_until, failure_count, source_count)
                VALUES (:uuid, :scope, :subject, :lockedAt, :lockedUntil, :failureCount, :sourceCount)
                ON CONFLICT (scope, subject) DO UPDATE SET
                    locked_until = EXCLUDED.locked_until,
                    failure_count = EXCLUDED.failure_count,
                    source_count = EXCLUDED.source_count
                SQL,
            [
                'uuid' => $uuid->toRfc4122(),
                'scope' => $scope,
                'subject' => $subject,
                // Timestamps are timestamptz. Binding a bare "Y-m-d H:i:s" would be read in the
                // session timezone, so the offset is written out explicitly.
                'lockedAt' => $lockedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
                'lockedUntil' => $lockedUntil->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
                'failureCount' => $failureCount,
                'sourceCount' => $sourceCount,
            ],
        );
    }

    public function deleteExpired(\DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('l')
            ->delete()
            ->where('l.lockedUntil <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
