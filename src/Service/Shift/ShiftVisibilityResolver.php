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
        // Draft shifts are only ever visible to managers working the planner,
        // never through normal browsing. Managers reach drafts via the planner
        // controllers directly, which do their own permission checks.
        if ($shift->getState() !== ShiftState::PUBLISHED) {
            return false;
        }

        return match ($shift->getAudience()) {
            ShiftAudience::PUBLIC_VOLUNTEER => true,
            ShiftAudience::ALL_STAFF => $user !== null && $user->isStaff(),
            ShiftAudience::DEPARTMENT_STAFF => $user !== null && $user->isStaff()
                && $shift->getDepartment() !== null
                && $this->memberships->userIsMember($user, $shift->getDepartment()),
            ShiftAudience::INVITE_ONLY => $user !== null
                && $this->entries->findOneByShiftAndUser($shift, $user) !== null,
        };
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
