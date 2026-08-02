<?php

namespace App\Security;

use App\Entity\Department;
use App\Entity\User;

/**
 * Which departments a user holds a privilege in.
 *
 * {@see PrivilegeVoter} answers "may this user act on THIS resource". This answers the inverse
 * question - "which departments does this user hold the privilege in at all" - which is what the
 * Mercure topic list needs, because a topic is minted ahead of any resource.
 *
 * Both must agree, so the voter is built on this class rather than repeating the rules. Getting the
 * two out of step would silently hand a manager every department's topics.
 */
final class PrivilegeScopeResolver
{
    /**
     * Departments in which the user holds the privilege.
     *
     * Returns null for "every department", which is the case for an administrator, for a sub-admin
     * holding a sub-admin-level privilege, and - importantly - for anyone whose granting group
     * assignment carries no department scope at all. An unscoped assignment is deliberately
     * event-wide; treating it as "no departments" would silently mute those users.
     *
     * Returns an empty array when the user does not hold the privilege at all.
     *
     * @return Department[]|null
     */
    public function departmentsFor(User $user, string $privilege): ?array
    {
        $roles = $user->getRoles();
        if (\in_array('ROLE_ADMIN', $roles, true) || $user->hasPrivilege(PrivilegeCatalog::SUPER)) {
            return null;
        }

        if (\in_array('ROLE_SUBADMIN', $roles, true) && PrivilegeCatalog::level($privilege) === PrivilegeCatalog::LEVEL_SUBADMIN) {
            return null;
        }

        $departments = [];
        $granting = false;
        foreach ($user->getActiveAssignments() as $assignment) {
            foreach ($assignment->getGroup()->getPrivileges() as $held) {
                if ($held->getName() !== $privilege) {
                    continue;
                }

                $granting = true;
                $scope = $assignment->getDepartment();
                if ($scope === null) {
                    return null;
                }
                if (!\in_array($scope, $departments, true)) {
                    $departments[] = $scope;
                }
                break;
            }
        }

        return $granting ? $departments : [];
    }

    /** Whether the user holds the privilege anywhere at all. */
    public function holds(User $user, string $privilege): bool
    {
        return $this->departmentsFor($user, $privilege) !== [];
    }
}
