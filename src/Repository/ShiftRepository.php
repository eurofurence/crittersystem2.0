<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Shift>
 */
class ShiftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shift::class);
    }

    public function countForDepartment(Department $department): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.department = :department')
            ->setParameter('department', $department)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Shift[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['startsAt' => 'ASC']);
    }

    /** @return Shift[] Shifts that have not yet ended, soonest first. */
    public function findUpcoming(?\DateTimeImmutable $from = null): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.endsAt >= :now')
            ->setParameter('now', $from ?? new \DateTimeImmutable())
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Shift[] published, not-yet-ended shifts with a staff-only audience,
     *                 for the staff application landing (visibility applied later)
     */
    public function findUpcomingStaffPublished(?\DateTimeImmutable $from = null): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.endsAt >= :now')
            ->andWhere('s.state = :published')
            ->andWhere('s.audience != :public')
            ->setParameter('now', $from ?? new \DateTimeImmutable())
            ->setParameter('published', ShiftState::PUBLISHED->value)
            ->setParameter('public', ShiftAudience::PUBLIC_VOLUNTEER->value)
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Shift[] every shift (draft and published) owned by the department
     *                 that starts within [$from, $to), for the planner grid
     */
    public function findForDepartmentBetween(Department $department, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.department = :department')
            ->andWhere('s.startsAt >= :from AND s.startsAt < :to')
            ->setParameter('department', $department)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Restrict a query to what a volunteer may browse: published
     * public shifts only. Staff-only audiences and drafts never appear.
     */
    public function applyPublicVisibility(QueryBuilder $qb, string $alias = 's'): void
    {
        $qb->andWhere(\sprintf('%s.state = :visState', $alias))
            ->andWhere(\sprintf('%s.audience = :visAudience', $alias))
            ->setParameter('visState', ShiftState::PUBLISHED->value)
            ->setParameter('visAudience', ShiftAudience::PUBLIC_VOLUNTEER->value);
    }

    /** @return Shift[] published public shifts that start on the given calendar day, with optional filters */
    public function findForDay(\DateTimeImmutable $day, ?Location $location = null, ?ShiftTask $shiftTask = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.startsAt >= :from AND s.startsAt < :to')
            ->setParameter('from', $day->setTime(0, 0))
            ->setParameter('to', $day->setTime(0, 0)->modify('+1 day'))
            ->orderBy('s.startsAt', 'ASC');
        $this->applyPublicVisibility($qb);

        if ($location !== null) {
            $qb->andWhere('s.location = :location')->setParameter('location', $location);
        }
        if ($shiftTask !== null) {
            $qb->andWhere('s.shiftTask = :shiftTask')->setParameter('shiftTask', $shiftTask);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Distinct calendar days (Y-m-d) that have at least one upcoming shift,
     * for the date selector.
     *
     * @return string[]
     */
    public function findUpcomingDays(int $limit = 30): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.startsAt AS d')
            ->andWhere('s.endsAt >= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.startsAt', 'ASC');
        $this->applyPublicVisibility($qb);

        /** @var array<int, array{d: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getResult();

        $days = [];
        foreach ($rows as $row) {
            $days[$row['d']->format('Y-m-d')] = true;
        }

        return \array_slice(array_keys($days), 0, $limit);
    }
}
