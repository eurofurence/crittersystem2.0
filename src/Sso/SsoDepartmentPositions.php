<?php

declare(strict_types=1);

namespace App\Sso;

use App\Entity\Department;
use App\Entity\User;
use App\Enum\DepartmentPosition;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Derives a user's position inside the departments SSO placed them in.
 *
 * The identity provider does not model "manager of department X". It models two global roles, and
 * membership of a department comes separately from an SsoGroupMapping. Combining the two gives the
 * position: department role alone means staff, plus the manager role means department manager, plus
 * the shift-manager role means shift manager. Holding both roles resolves to the higher one.
 *
 * The resolved position is reconciled, not merely added: the positional group that no longer applies
 * is removed, so a demotion at the identity provider takes effect on the next login. Only the two
 * positional groups on the departments mapped in this login are touched — a delegated shift manager
 * is an approval-backed, time-boxed grant and is never rewritten here.
 */
final class SsoDepartmentPositions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GroupRepository $groups,
        private readonly SsoRoleSettings $settings,
    ) {
    }

    /**
     * @param string[]      $ssoGroupIds the group/role IDs claimed by the identity provider
     * @param Department[]  $departments the departments the mappings granted in this login
     */
    public function apply(User $user, array $ssoGroupIds, array $departments): void
    {
        if ($departments === []) {
            return;
        }

        $position = $this->resolve($ssoGroupIds);
        foreach ($departments as $department) {
            $this->reconcile($user, $department, $position);
        }
    }

    /** @param string[] $ssoGroupIds */
    public function resolve(array $ssoGroupIds): DepartmentPosition
    {
        $managerRole = $this->settings->departmentManagerRole();
        $shiftManagerRole = $this->settings->shiftManagerRole();

        if ($managerRole !== null && \in_array($managerRole, $ssoGroupIds, true)) {
            return DepartmentPosition::MANAGER;
        }
        if ($shiftManagerRole !== null && \in_array($shiftManagerRole, $ssoGroupIds, true)) {
            return DepartmentPosition::SHIFT_MANAGER;
        }

        return DepartmentPosition::STAFF;
    }

    private function reconcile(User $user, Department $department, DepartmentPosition $position): void
    {
        $wanted = $position->groupSlug();
        $held = false;

        foreach ($user->getGroupAssignments() as $assignment) {
            if ($assignment->getDepartment() !== $department) {
                continue;
            }
            $slug = $assignment->getGroup()->getSlug();
            if ($slug === $wanted) {
                $held = true;
            } elseif (\in_array($slug, DepartmentPosition::assignableSlugs(), true)) {
                $this->em->remove($assignment);
                $user->getGroupAssignments()->removeElement($assignment);
            }
        }

        if ($held) {
            return;
        }

        $group = $this->groups->findOneBySlug($wanted);
        if ($group !== null) {
            $user->assignGroup($group, $department);
        }
    }
}
