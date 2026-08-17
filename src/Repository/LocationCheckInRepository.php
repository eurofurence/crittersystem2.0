<?php

namespace App\Repository;

use App\Entity\LocationCheckIn;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocationCheckIn>
 */
class LocationCheckInRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocationCheckIn::class);
    }

    /**
     * Every row for one day, oldest first, with the subject and their groups joined.
     *
     * The groups are fetch-joined because the caller asks each subject whether they are staff to
     * split the counts, and that would otherwise be a query per row.
     *
     * @return LocationCheckIn[]
     */
    public function findForDate(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.user', 'u')->addSelect('u')
            ->leftJoin('u.groupAssignments', 'ga')->addSelect('ga')
            ->leftJoin('ga.group', 'g')->addSelect('g')
            ->andWhere('c.localDate = :date')
            ->setParameter('date', $date->setTime(0, 0))
            ->orderBy('c.occurredAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The state each person ended the day in, keyed by user id.
     *
     * Reduced here rather than in SQL: the newest row per user is a window function or a lateral
     * join, neither of which DQL expresses, and a day holds hundreds of rows rather than millions.
     * Whoever is left holding an entry counts as inside; somebody whose last row is a withdrawal
     * does not, which is what makes an entry logged by mistake correctable without deleting it.
     *
     * @return array<int, LocationCheckIn> user id => their last row that day
     */
    public function latestPerUserForDate(\DateTimeImmutable $date): array
    {
        $latest = [];
        foreach ($this->findForDate($date) as $row) {
            $latest[(int) $row->getUser()->getId()] = $row;
        }

        return $latest;
    }

    /** The row that decides whether this person is currently inside, or null if they never came. */
    public function latestForUserOnDate(User $user, \DateTimeImmutable $date): ?LocationCheckIn
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.localDate = :date')
            ->setParameter('user', $user)
            ->setParameter('date', $date->setTime(0, 0))
            ->orderBy('c.occurredAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * One person's whole history, newest first, with the operator who acted joined.
     *
     * @return LocationCheckIn[]
     */
    public function findHistoryForUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.actor', 'a')->addSelect('a')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.occurredAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The days that have any activity, newest first, for the report's day picker.
     *
     * @return \DateTimeImmutable[]
     */
    public function findActiveDates(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT c.localDate AS localDate')
            ->orderBy('c.localDate', 'DESC')
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row): \DateTimeImmutable => new \DateTimeImmutable((string) $row['localDate']),
            $rows,
        );
    }
}
