<?php

namespace App\Service;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Department;
use App\Entity\User;
use App\Enum\DepartmentPosition;
use App\Repository\GroupRepository;
use App\Repository\UserGroupAssignmentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Places users in departments and moves them between positions.
 *
 * A position is a department-scoped group assignment, so setting one swaps the positional group and
 * leaves everything else the user holds in that department untouched. Grants made here are permanent,
 * unlike the delegated shift-manager grant, which expires at teardown
 * ({@see DelegatedManagerService}).
 *
 * SSO-managed users are refused: the identity provider owns their position and would overwrite any
 * change on the next login. Callers must check {@see User::isSsoManaged()} and say so in the UI.
 */
class DepartmentMemberService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GroupRepository $groups,
        private readonly UserGroupAssignmentRepository $assignments,
        private readonly AuditLogger $audit,
    ) {
    }

    public function positionOf(Department $department, User $user): ?DepartmentPosition
    {
        $held = null;
        foreach ($this->assignments->findActiveByDepartment($department) as $assignment) {
            if ($assignment->getUser()->getId() !== $user->getId()) {
                continue;
            }
            $position = DepartmentPosition::fromGroupSlug($assignment->getGroup()->getSlug());
            if ($position !== null && ($held === null || $position->outranks($held))) {
                $held = $position;
            }
        }

        return $held;
    }

    public function setPosition(Department $department, User $user, DepartmentPosition $position): void
    {
        $group = $this->groups->findOneBySlug($position->groupSlug());
        if ($group === null) {
            return;
        }

        $held = false;
        foreach ($this->assignments->findActiveByDepartment($department) as $assignment) {
            if ($assignment->getUser()->getId() !== $user->getId()) {
                continue;
            }
            $slug = $assignment->getGroup()->getSlug();
            if ($slug === $position->groupSlug()) {
                $held = true;
            } elseif (\in_array($slug, DepartmentPosition::assignableSlugs(), true)) {
                $this->em->remove($assignment);
            }
        }

        if (!$held) {
            $user->assignGroup($group, $department);
        }
        $this->em->flush();

        $this->audit->log(AuditEvents::ACCESS_CONTROL, AuditEvents::GRANT, [
            'resourceType' => 'Department',
            'resourceId' => $department->getId(),
            'details' => ['user' => $user->getId(), 'position' => $position->value],
        ]);
    }

    /** Drop every department-scoped assignment the user holds here, whatever the group. */
    public function remove(Department $department, User $user): void
    {
        foreach ($this->assignments->findActiveByDepartment($department) as $assignment) {
            if ($assignment->getUser()->getId() === $user->getId()) {
                $this->em->remove($assignment);
            }
        }
        $this->em->flush();

        $this->audit->log(AuditEvents::ACCESS_CONTROL, AuditEvents::REVOKE, [
            'resourceType' => 'Department',
            'resourceId' => $department->getId(),
            'details' => ['removed' => $user->getId()],
        ]);
    }
}
