<?php

namespace App\Service;

use App\Entity\Department;
use App\Entity\User;
use App\Enum\DepartmentPosition;
use App\Repository\DepartmentRepository;
use App\Repository\ShiftRepository;
use App\Repository\UserGroupAssignmentRepository;

/**
 * Derives department membership and staffing views. Membership is
 * expressed as department-scoped group assignments; managers/shift-managers are
 * identified by the assigned group's slug.
 */
class DepartmentService
{
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly UserGroupAssignmentRepository $assignments,
        private readonly ShiftRepository $shifts,
        private readonly HoursCacheService $hours,
        private readonly EventConfigStore $config,
    ) {
    }

    /**
     * Departments a staff viewer may see: all non-organizational
     * departments, plus organizational ones only for global admins. Departments
     * the viewer belongs to are flagged and sorted first.
     *
     * @return array<int, array{department: Department, member: bool}>
     */
    public function visibleDepartments(User $viewer): array
    {
        $isAdmin = $viewer->hasPrivilege('global:admin');
        $rows = [];
        foreach ($this->departments->findAllOrdered() as $department) {
            if ($department->isOrganizational() && !$isAdmin) {
                continue;
            }
            $rows[] = [
                'department' => $department,
                'member' => $this->assignments->userIsMember($viewer, $department),
            ];
        }

        usort($rows, static fn ($a, $b) => ($b['member'] <=> $a['member']) ?: strcmp($a['department']->getName(), $b['department']->getName()));

        return $rows;
    }

    /**
     * Classified members of a department. A user holding several positional groups is listed once,
     * under the highest one — department manager outranks shift manager outranks staff.
     *
     * @return array{managers: User[], shiftManagers: User[], staff: User[], nonStaff: User[], positions: array<int, DepartmentPosition>}
     */
    public function members(Department $department): array
    {
        /** @var array<int, User> $users */
        $users = [];
        /** @var array<int, DepartmentPosition> $positions */
        $positions = [];

        foreach ($this->assignments->findActiveByDepartment($department) as $assignment) {
            $user = $assignment->getUser();
            $uid = $user->getId();
            $users[$uid] = $user;

            $position = DepartmentPosition::fromGroupSlug($assignment->getGroup()->getSlug());
            if ($position !== null && (!isset($positions[$uid]) || $position->outranks($positions[$uid]))) {
                $positions[$uid] = $position;
            }
        }

        $managers = $shiftManagers = $staff = $nonStaff = [];
        foreach ($users as $uid => $user) {
            match ($positions[$uid] ?? null) {
                DepartmentPosition::MANAGER => $managers[] = $user,
                DepartmentPosition::SHIFT_MANAGER => $shiftManagers[] = $user,
                default => $user->isStaff() ? $staff[] = $user : $nonStaff[] = $user,
            };
        }

        return [
            'managers' => $managers,
            'shiftManagers' => $shiftManagers,
            'staff' => $staff,
            'nonStaff' => $nonStaff,
            'positions' => $positions,
        ];
    }

    /**
     * @return array{shiftCount: int, recommendedMaxHours: int}
     */
    public function staffing(Department $department): array
    {
        return [
            'shiftCount' => $this->shifts->countForDepartment($department),
            'recommendedMaxHours' => $this->config->getInt(
                EventConfigStore::KEY_HOURS_RECOMMENDED_MAX,
                EventConfigStore::DEFAULT_HOURS_RECOMMENDED_MAX,
            ),
        ];
    }

    public function plannedHours(User $user): float
    {
        return $this->hours->get($user)->getTotalHours();
    }

    public function overThreshold(User $user): bool
    {
        $max = $this->config->getInt(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, EventConfigStore::DEFAULT_HOURS_RECOMMENDED_MAX);

        return $this->plannedHours($user) > $max;
    }
}
