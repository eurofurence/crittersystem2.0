<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\ShiftTask;
use App\Repository\DepartmentRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who may manage which shift tasks.
 *
 * A task either belongs to a department or is global (no department). The rules:
 *
 *   - a department's task is managed by anyone with `shift:manage` on THAT department
 *     (department managers and shift managers, including delegated ones);
 *   - a global task is the shared vocabulary every department draws on, so only a global admin
 *     may create, change or delete one;
 *   - admins are unrestricted.
 *
 * The department scope is answered by the authorization checker rather than by re-reading group
 * assignments here, so this cannot drift from PrivilegeVoter.
 */
final class ShiftTaskAccess
{
    public function __construct(
        private readonly Security $security,
        private readonly DepartmentRepository $departments,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->security->isGranted('global:admin');
    }

    /**
     * Departments the current user may manage shift tasks for.
     *
     * @return list<Department>
     */
    public function manageableDepartments(): array
    {
        return array_values(array_filter(
            $this->departments->findAllOrdered(),
            fn (Department $department) => $this->security->isGranted('shift:manage', $department),
        ));
    }

    /**
     * May the current user create/change/delete this task? A task with no department is global and
     * affects every department, so only an admin may touch it.
     */
    public function canManage(ShiftTask $task): bool
    {
        $department = $task->getDepartment();

        return $department === null
            ? $this->isAdmin()
            : $this->security->isGranted('shift:manage', $department);
    }

    /** May the current user own a task in this department (null = the global pool)? */
    public function canManageDepartment(?Department $department): bool
    {
        return $department === null
            ? $this->isAdmin()
            : $this->security->isGranted('shift:manage', $department);
    }

    /**
     * The tasks a user may pick when planning for a department: that department's own tasks plus
     * the global ones. Admins planning for a department see the same list - the point is relevance,
     * not secrecy.
     *
     * @param ShiftTask[] $all
     *
     * @return list<ShiftTask>
     */
    public function forDepartment(array $all, ?Department $department): array
    {
        return array_values(array_filter(
            $all,
            static fn (ShiftTask $task) => $task->getDepartment() === null
                || ($department !== null && $task->getDepartment() === $department),
        ));
    }

    /**
     * The tasks to list on the management screen: everything for an admin, otherwise the global
     * pool plus the tasks of the departments the user manages.
     *
     * @param ShiftTask[] $all
     *
     * @return list<ShiftTask>
     */
    public function visible(array $all): array
    {
        if ($this->isAdmin()) {
            return array_values($all);
        }

        $manageable = $this->manageableDepartments();

        return array_values(array_filter(
            $all,
            static fn (ShiftTask $task) => $task->getDepartment() === null
                || \in_array($task->getDepartment(), $manageable, true),
        ));
    }
}
