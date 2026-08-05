<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftGroup;
use App\Entity\ShiftTask;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Shift>
 */
class ShiftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shift::class);
    }

    public function findOneByUuid(string $uuid): ?Shift
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
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

    /**
     * @return Shift[] Shifts that have not yet ended, soonest first.
     *
     * Applies no visibility filter - drafts and staff-only audiences are
     * included. Only for callers that have already established the viewer may
     * see them; anything reachable without a login wants
     * {@see findUpcomingPublic()}.
     */
    public function findUpcoming(?\DateTimeImmutable $from = null): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.endsAt >= :now')
            ->setParameter('now', $from ?? new \DateTimeImmutable())
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Shift[] published public shifts that have not yet ended, soonest first */
    public function findUpcomingPublic(?\DateTimeImmutable $from = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.endsAt >= :now')
            ->setParameter('now', $from ?? new \DateTimeImmutable())
            ->orderBy('s.startsAt', 'ASC');
        $this->applyPublicVisibility($qb);

        return $qb->getQuery()->getResult();
    }

    /** @return Shift[] published public shifts at a location, soonest first */
    public function findPublicForLocation(Location $location): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.location = :location')
            ->setParameter('location', $location)
            ->orderBy('s.startsAt', 'ASC');
        $this->applyPublicVisibility($qb);

        return $qb->getQuery()->getResult();
    }

    /** @return Shift[] published public shifts for a shift task, soonest first */
    public function findPublicForShiftTask(ShiftTask $shiftTask): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.shiftTask = :shiftTask')
            ->setParameter('shiftTask', $shiftTask)
            ->orderBy('s.startsAt', 'ASC');
        $this->applyPublicVisibility($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Shift[] published, not-yet-ended shifts with a staff-only audience,
     *                 for the staff application landing (visibility applied later)
     */
    public function findUpcomingStaffPublished(?\DateTimeImmutable $from = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.endsAt >= :now')
            ->andWhere('s.state = :published')
            ->andWhere('s.audience != :public')
            ->setParameter('now', $from ?? new \DateTimeImmutable())
            ->setParameter('published', ShiftState::PUBLISHED->value)
            ->setParameter('public', ShiftAudience::PUBLIC_VOLUNTEER->value)
            ->orderBy('s.startsAt', 'ASC');
        $this->joinShiftGroup($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Fetch each shift's group and the group's other members alongside it.
     *
     * Every list that renders a group badge asks the resolver for the members, which without this
     * costs a query for the group and another for its shifts on every single row.
     */
    public function joinShiftGroup(QueryBuilder $qb, string $alias = 's'): void
    {
        $qb->leftJoin($alias.'.shiftGroup', 'grp')->addSelect('grp')
            ->leftJoin('grp.shifts', 'grpShifts')->addSelect('grpShifts');
    }

    /**
     * Departments currently offering a shift any staff member may see.
     *
     * The staff apply screen lists these under "other departments", so their capacity changes have
     * to reach a viewer who is not a member of them - otherwise the only rows that ever update are
     * the ones in the viewer's own departments.
     *
     * Deliberately only the all-staff audience: a department-staff shift is already covered by
     * membership, and anything narrower is not visible to a non-member at all.
     *
     * @return Department[]
     */
    public function findDepartmentsWithUpcomingAllStaffShifts(?\DateTimeImmutable $from = null): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT d')
            ->from(Department::class, 'd')
            ->join(Shift::class, 's', 'WITH', 's.department = d')
            ->andWhere('s.endsAt >= :now')
            ->andWhere('s.state = :published')
            ->andWhere('s.audience = :allStaff')
            ->setParameter('now', $from ?? new \DateTimeImmutable())
            ->setParameter('published', ShiftState::PUBLISHED->value)
            ->setParameter('allStaff', ShiftAudience::ALL_STAFF->value)
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
            ->addOrderBy('s.endsAt', 'DESC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Shift[] the department's shifts that are not yet in a shift group, soonest first,
     *                 for the group member picker
     */
    public function findForDepartmentUngrouped(Department $department): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.department = :department')
            ->andWhere('s.shiftGroup IS NULL')
            ->setParameter('department', $department)
            ->orderBy('s.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Shifts a manager may add to a shift group, narrowed by the picker's filters.
     *
     * Restricted to the group's own department, which is what keeps a group single-department.
     * Shifts already in ANOTHER group are included so the picker can show them disabled and name the
     * owner: leaving them out silently is what makes a manager wonder whether a shift exists at all.
     * Shifts already in THIS group are excluded, because they are listed above as members.
     *
     * @param ShiftGroup|null $exclude the group being edited
     *
     * @return Shift[] soonest first, capped
     */
    public function findGroupCandidates(
        Department $department,
        ?ShiftGroup $exclude = null,
        ?\DateTimeImmutable $dayFrom = null,
        ?\DateTimeImmutable $dayTo = null,
        ?ShiftAudience $audience = null,
        ?ShiftTask $task = null,
        ?string $query = null,
        bool $includePast = false,
        ?\DateTimeImmutable $now = null,
        int $limit = 250,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.shiftGroup', 'grp')->addSelect('grp')
            ->leftJoin('s.location', 'loc')->addSelect('loc')
            ->leftJoin('s.shiftTask', 'tsk')->addSelect('tsk')
            ->andWhere('s.department = :department')
            ->setParameter('department', $department)
            ->orderBy('s.startsAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults($limit);

        if ($exclude !== null && $exclude->getId() !== null) {
            $qb->andWhere('s.shiftGroup IS NULL OR s.shiftGroup != :exclude')
                ->setParameter('exclude', $exclude);
        }
        if (!$includePast) {
            $qb->andWhere('s.endsAt >= :now')->setParameter('now', $now ?? new \DateTimeImmutable());
        }
        // A half-open range anchored on local midnight: shifts are stored as UTC instants, so
        // comparing a date string against the column would drift across the timezone offset.
        if ($dayFrom !== null && $dayTo !== null) {
            $qb->andWhere('s.startsAt >= :dayFrom AND s.startsAt < :dayTo')
                ->setParameter('dayFrom', $dayFrom)
                ->setParameter('dayTo', $dayTo);
        }
        if ($audience !== null) {
            $qb->andWhere('s.audience = :audience')->setParameter('audience', $audience->value);
        }
        if ($task !== null) {
            $qb->andWhere('s.shiftTask = :task')->setParameter('task', $task);
        }
        if ($query !== null && $query !== '') {
            $qb->andWhere('LOWER(s.title) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($query).'%');
        }

        return $qb->getQuery()->getResult();
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
    public function findForDay(\DateTimeImmutable $day, \DateTimeZone $tz, ?Location $location = null, ?ShiftTask $shiftTask = null): array
    {
        // Shifts are stored in UTC
        $from = $day->setTimezone($tz)->setTime(0, 0);
        $to = $from->modify('+1 day');
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.startsAt >= :from AND s.startsAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.startsAt', 'ASC');
        $this->joinShiftGroup($qb);
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
    public function findUpcomingDays(\DateTimeZone $tz, int $limit = 30): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.startsAt AS d')
            ->andWhere('s.endsAt >= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.startsAt', 'ASC');
        $this->applyPublicVisibility($qb);

        /** @var array<int, array{d: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getResult();

        // Group by the local calendar day (stored instants are UTC)
        $days = [];
        foreach ($rows as $row) {
            $days[$row['d']->setTimezone($tz)->format('Y-m-d')] = true;
        }

        return \array_slice(array_keys($days), 0, $limit);
    }
}
