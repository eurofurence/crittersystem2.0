<?php

namespace App\Service;

use App\Entity\Department;
use App\Entity\User;
use App\Enum\DepartmentPosition;
use App\Repository\DepartmentRepository;
use App\Repository\ShiftRepository;
use App\Repository\UserGroupAssignmentRepository;
use App\Repository\UserRepository;

/**
 * Derives department membership and staffing views. Membership is
 * expressed as department-scoped group assignments; managers/shift-managers are
 * identified by the assigned group's slug.
 */
class DepartmentService
{
    /** Members shown per page in one section of the department dashboard. */
    public const MEMBERS_PER_PAGE = 25;

    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly UserGroupAssignmentRepository $assignments,
        private readonly ShiftRepository $shifts,
        private readonly HoursCacheService $hours,
        private readonly EventConfigStore $config,
        private readonly UserRepository $users,
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
     * under the highest one - department manager outranks shift manager outranks staff.
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

        $this->users->preloadGroupAssignments(array_values($users));

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

    /**
     * One page of a member section, narrowed by an optional username search.
     *
     * Matching is on the username only, never the real name: a real name is shown only where the
     * subject consented to it, so matching on it would let a viewer confirm a name they may not see.
     *
     * @param User[] $users a whole section, already classified
     *
     * @return array{items: User[], total: int, totalAll: int, page: int, pages: int, query: string, perPage: int}
     */
    public function paginateMembers(array $users, string $query = '', int $page = 1, int $perPage = self::MEMBERS_PER_PAGE): array
    {
        $totalAll = \count($users);
        $query = trim($query);
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $users = array_values(array_filter(
                $users,
                static fn (User $user): bool => str_contains(mb_strtolower($user->getName()), $needle),
            ));
        }

        $total = \count($users);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        return [
            'items' => \array_slice($users, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'totalAll' => $totalAll,
            'page' => $page,
            'pages' => $pages,
            'query' => $query,
            'perPage' => $perPage,
        ];
    }

    /**
     * Planned hours and the over-threshold flag for the given users, keyed by user id.
     *
     * Batched deliberately: the threshold is read once rather than per user, and the hours caches
     * come from one query. Call it with the rows of a single page, not a whole department.
     *
     * @param User[] $users
     *
     * @return array<int, array{hours: float, over: bool}>
     */
    public function statsFor(array $users): array
    {
        $max = $this->config->getInt(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, EventConfigStore::DEFAULT_HOURS_RECOMMENDED_MAX);
        $caches = $this->hours->getMany($users);

        $stats = [];
        foreach ($users as $user) {
            $hours = $caches[$user->getId()]->getTotalHours();
            $stats[$user->getId()] = ['hours' => $hours, 'over' => $hours > $max];
        }

        return $stats;
    }
}
