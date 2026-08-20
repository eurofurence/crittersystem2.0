<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserHoursCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserHoursCache>
 */
class UserHoursCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserHoursCache::class);
    }

    public function findOneByUser(User $user): ?UserHoursCache
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Caches for many users in one query, keyed by user id. Lets a list screen avoid a
     * per-row lookup; users without a cache row are simply absent from the result.
     *
     * @param User[] $users
     *
     * @return array<int, UserHoursCache>
     */
    public function findByUsers(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.user IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$row->getUser()->getId()] = $row;
        }

        return $byUser;
    }

    /**
     * The users whose cached hours could no longer be right.
     *
     * Two causes, and they are answered differently because only one of them has a write behind it.
     * A shift *ending* changes somebody's completed hours while nothing at all happens in the
     * application, so those users are found by comparing the shift's end against the moment their
     * row was last calculated. Everything else, a worklog or a no-show, arrives as a write and is
     * marked by {@see markDirtyForUsers()}.
     *
     * Both clauses are self-healing: they describe a state rather than an event, so a run that is
     * missed, or a job stopped for a day, catches up on the next run instead of losing the work.
     *
     * A user who has never had a row is included only when they have something to count. Otherwise
     * a first run would write a row of zeroes for every account that has never taken a shift.
     *
     * @return int[] user ids
     */
    public function findUserIdsNeedingRecalculation(?int $limit = null): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT u.id
            FROM users u
            LEFT JOIN user_hours_cache c ON c.user_id = u.id
            WHERE
                c.dirty = true
                OR EXISTS (
                    SELECT 1 FROM shift_entries e
                    JOIN shifts s ON s.id = e.shift_id
                    WHERE e.user_id = u.id
                      AND s.ends_at <= now()
                      AND (c.id IS NULL OR s.ends_at > c.last_calculated_at)
                )
                OR (c.id IS NULL AND EXISTS (SELECT 1 FROM worklogs w WHERE w.user_id = u.id))
            ORDER BY u.id
            SQL;

        if ($limit !== null) {
            $sql .= ' LIMIT '.max(1, $limit);
        }

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->getEntityManager()->getConnection()->fetchAllAssociative($sql),
        );
    }

    /** Every user with anything to count, for a forced rebuild. */
    public function findAllUserIdsWithHours(): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT u.id
            FROM users u
            WHERE EXISTS (SELECT 1 FROM shift_entries e WHERE e.user_id = u.id)
               OR EXISTS (SELECT 1 FROM worklogs w WHERE w.user_id = u.id)
               OR EXISTS (SELECT 1 FROM user_hours_cache c WHERE c.user_id = u.id)
            ORDER BY u.id
            SQL;

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->getEntityManager()->getConnection()->fetchAllAssociative($sql),
        );
    }

    /**
     * Flag these users' rows as needing recalculation, in one statement.
     *
     * Written straight to the database rather than through the unit of work: this runs from a
     * flush listener, and loading entities to change them there would schedule more work inside the
     * flush that triggered it. A user with no row yet needs no flag, because the sweep already picks
     * up anybody who has hours and has never been calculated.
     *
     * @param int[] $userIds
     */
    public function markDirtyForUsers(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE user_hours_cache SET dirty = true WHERE user_id IN (?)',
            [array_values(array_unique($userIds))],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }
}
