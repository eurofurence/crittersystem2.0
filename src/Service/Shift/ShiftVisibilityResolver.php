<?php

namespace App\Service\Shift;

use App\Entity\Shift;
use App\Entity\User;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserGroupAssignmentRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Decides which shifts a viewer may see, from the audience mode and
 * publication state. Staff-only shifts are never exposed to
 * volunteers; draft shifts are invisible to everyone in normal browsing.
 *
 * The same rules are expressed two ways: {@see isVisibleTo()} for a single
 * loaded shift, and {@see applyPublicVisibility()} for the volunteer browser
 * query so drafts and staff-only shifts never reach the list.
 */
final class ShiftVisibilityResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly UserGroupAssignmentRepository $memberships,
        private readonly ShiftEntryRepository $entries,
    ) {
    }

    /**
     * Whether the given user may see this shift at all. A null user is an
     * anonymous/guest viewer and only sees published public shifts.
     */
    public function isVisibleTo(Shift $shift, ?User $user): bool
    {
        return $this->decide(
            $shift,
            $user,
            fn (): bool => $user !== null && $shift->getDepartment() !== null
                && $this->memberships->userIsMember($user, $shift->getDepartment()),
            fn (): bool => $user !== null && $this->entries->findOneByShiftAndUser($shift, $user) !== null,
        );
    }

    /**
     * The same decision for a whole list, with the two per-shift lookups resolved once for the set
     * instead of once per shift. A screen that filters several hundred shifts one at a time spends a
     * query per shift before it can draw anything.
     *
     * @param Shift[] $shifts
     *
     * @return list<Shift>
     */
    public function filterVisible(array $shifts, ?User $user): array
    {
        if ($user === null) {
            return array_values(array_filter($shifts, fn (Shift $shift): bool => $this->isVisibleTo($shift, null)));
        }

        $memberDepartments = [];
        foreach ($this->memberships->findActiveDepartmentsForUser($user) as $department) {
            $memberDepartments[$department->getId()] = true;
        }
        $invited = $this->entries->findByUserAndShifts($user, $shifts);

        return array_values(array_filter($shifts, fn (Shift $shift): bool => $this->decide(
            $shift,
            $user,
            static fn (): bool => isset($memberDepartments[$shift->getDepartment()?->getId()]),
            static fn (): bool => isset($invited[$shift->getId()]),
        )));
    }

    /**
     * The audience rules, with the two lookups they need supplied by the caller so a single shift
     * and a whole list can be answered by the same code.
     */
    private function decide(Shift $shift, ?User $user, callable $isDepartmentMember, callable $hasEntry): bool
    {
        // Draft shifts are only ever visible to managers working the planner,
        // never through normal browsing. Managers reach drafts via the planner
        // controllers directly, which do their own permission checks.
        if ($shift->getState() !== ShiftState::PUBLISHED) {
            return false;
        }

        return match ($shift->getAudience()) {
            ShiftAudience::PUBLIC_VOLUNTEER => true,
            ShiftAudience::ALL_STAFF => $user !== null && $user->isStaff(),
            ShiftAudience::DEPARTMENT_STAFF => $user !== null && $user->isStaff() && $isDepartmentMember(),
            ShiftAudience::INVITE_ONLY => $user !== null && $hasEntry(),
        };
    }

    /**
     * The same rules again, as a query predicate, so a list can be narrowed to what the viewer may
     * see *before* it is capped or paged.
     *
     * Filtering after a LIMIT silently drops shifts the viewer is entitled to: the rows the cap
     * admitted are spent on shifts that are then thrown away, and with no cursor there is no second
     * request that reaches the rest. {@see \App\Tests\Integration\ShiftVisibilityParityTest} pins
     * this against {@see isVisibleTo()}, which is the other expression of the same rule.
     *
     * @param int[] $memberDepartmentIds ids of departments the user is an active member of
     */
    public function applyVisibilityFor(QueryBuilder $qb, ?User $user, array $memberDepartmentIds = [], string $alias = 's'): void
    {
        $qb->andWhere(\sprintf('%s.state = :visState', $alias))
            ->setParameter('visState', ShiftState::PUBLISHED->value);

        if ($user === null) {
            $qb->andWhere(\sprintf('%s.audience = :visPublic', $alias))
                ->setParameter('visPublic', ShiftAudience::PUBLIC_VOLUNTEER->value);

            return;
        }

        $clauses = [\sprintf('%s.audience = :visPublic', $alias)];
        $qb->setParameter('visPublic', ShiftAudience::PUBLIC_VOLUNTEER->value);

        if ($user->isStaff()) {
            $clauses[] = \sprintf('%s.audience = :visAllStaff', $alias);
            $qb->setParameter('visAllStaff', ShiftAudience::ALL_STAFF->value);

            if ($memberDepartmentIds !== []) {
                $clauses[] = \sprintf('(%s.audience = :visDeptStaff AND IDENTITY(%s.department) IN (:visDepartments))', $alias, $alias);
                $qb->setParameter('visDeptStaff', ShiftAudience::DEPARTMENT_STAFF->value)
                    ->setParameter('visDepartments', $memberDepartmentIds);
            }
        }

        $clauses[] = \sprintf(
            '(%s.audience = :visInvite AND EXISTS (SELECT 1 FROM %s visEntry WHERE visEntry.shift = %s AND visEntry.user = :visUser))',
            $alias,
            \App\Entity\ShiftEntry::class,
            $alias,
        );
        $qb->setParameter('visInvite', ShiftAudience::INVITE_ONLY->value)
            ->setParameter('visUser', $user);

        $qb->andWhere('('.implode(' OR ', $clauses).')');
    }

    /**
     * Ids of the departments the user is an active member of, for
     * {@see applyVisibilityFor()}. Separate so a caller filtering several queries pays for it once.
     *
     * @return int[]
     */
    public function memberDepartmentIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $ids = [];
        foreach ($this->memberships->findActiveDepartmentsForUser($user) as $department) {
            $ids[] = $department->getId();
        }

        return $ids;
    }

    /** Staff-only audiences are hidden from volunteers regardless of state. */
    public function isStaffOnly(Shift $shift): bool
    {
        return $shift->getAudience()->isStaffOnly();
    }

    /**
     * Restrict a shift query to what a volunteer may browse:
     * published public shifts only. Staff-only shifts and drafts never appear.
     */
    public function applyPublicVisibility(QueryBuilder $qb, string $alias = 's'): void
    {
        $qb->andWhere(\sprintf('%s.state = :visState', $alias))
            ->andWhere(\sprintf('%s.audience = :visAudience', $alias))
            ->setParameter('visState', ShiftState::PUBLISHED->value)
            ->setParameter('visAudience', ShiftAudience::PUBLIC_VOLUNTEER->value);
    }

    /**
     * Audience modes the given staff user may see in the staff shift manager,
     * before per-department membership is applied. Used to scope staff queries.
     *
     * @return list<ShiftAudience>
     */
    public function staffAudiences(): array
    {
        return [
            ShiftAudience::PUBLIC_VOLUNTEER,
            ShiftAudience::ALL_STAFF,
            ShiftAudience::DEPARTMENT_STAFF,
            ShiftAudience::INVITE_ONLY,
        ];
    }
}
