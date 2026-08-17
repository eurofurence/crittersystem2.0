<?php

namespace App\Service\Board;

use App\Entity\Department;
use App\Entity\User;
use App\Repository\DepartmentRepository;
use App\Security\PrivilegeScopeResolver;

/**
 * Which departments a user may open the board for.
 *
 * Built on {@see PrivilegeScopeResolver} rather than on `is_granted('board:view')`, because a scoped
 * privilege checked without a resource grants unconditionally - asking the voter here would offer
 * every manager every department in the rail, and the board would then render data they may not see.
 *
 * The resolver's null means "every department", which is an administrator or an unscoped grant.
 * Organizational departments are excluded throughout: they cannot own shifts, so a board for one
 * would always be empty.
 */
final class BoardAccess
{
    public function __construct(
        private readonly PrivilegeScopeResolver $scopes,
        private readonly DepartmentRepository $departments,
    ) {
    }

    /**
     * Ordered by the repository's ordering rather than by grant order, so the rail does not reshuffle
     * when somebody's group assignments change.
     *
     * @return list<Department> the rail contents, ordered as departments are ordered everywhere else
     */
    public function departmentsFor(User $user): array
    {
        $held = $this->scopes->departmentsFor($user, 'board:view');

        if ($held === null) {
            return array_values(array_filter(
                $this->departments->findAllOrdered(),
                static fn (Department $department): bool => $department->canOwnShifts(),
            ));
        }

        $allowed = [];
        foreach ($held as $department) {
            if ($department->canOwnShifts()) {
                $allowed[$department->getId()] = $department;
            }
        }

        return array_values(array_filter(
            $this->departments->findAllOrdered(),
            static fn (Department $department): bool => isset($allowed[$department->getId()]),
        ));
    }

    public function canView(User $user, Department $department): bool
    {
        if (!$department->canOwnShifts()) {
            return false;
        }

        $held = $this->scopes->departmentsFor($user, 'board:view');

        return $held === null || \in_array($department, $held, true);
    }
}
