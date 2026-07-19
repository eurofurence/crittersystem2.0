<?php

namespace App\Security;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\VolunteerType;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants fine-grained access based on a user's effective permissions.
 *
 * Enables checks like `is_granted('user:view')` in controllers and
 * `{{ is_granted('news:view') }}` in Twig. It only votes on attributes that are
 * known permission names (see {@see PrivilegeCatalog}); any other attribute
 * (ROLE_*, IS_AUTHENTICATED_*, ...) is left to other voters.
 *
 * The `global:admin` permission means "full administrative access", so it
 * satisfies every check.
 *
 * Department scoping: for the permissions in {@see PrivilegeCatalog::SCOPED},
 * when a resource subject is supplied the permission is only granted if the user
 * holds it through an assignment that is either unscoped or scoped to the
 * resource's department. Without a subject these behave like ordinary checks
 * (e.g. "may reach the management list at all").
 *
 * @extends Voter<string, mixed>
 */
class PrivilegeVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return PrivilegeCatalog::isPrivilege($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // ROLE_ADMIN has full, unrestricted access: it satisfies every
        // permission check, whether or not the global:admin privilege is attached
        // to one of its groups. The explicit super-privilege keeps working too.
        $roles = $user->getRoles();
        if (\in_array('ROLE_ADMIN', $roles, true) || $user->hasPrivilege(PrivilegeCatalog::SUPER)) {
            return true;
        }

        // ROLE_SUBADMIN mirrors ROLE_ADMIN but is denied the admin-level/critical
        // permissions (configuration, audit, PII, RBAC management).
        // It therefore holds every sub-admin-level permission unscoped.
        if (\in_array('ROLE_SUBADMIN', $roles, true) && PrivilegeCatalog::level($attribute) === PrivilegeCatalog::LEVEL_SUBADMIN) {
            return true;
        }

        $granting = [];
        foreach ($user->getActiveAssignments() as $assignment) {
            foreach ($assignment->getGroup()->getPrivileges() as $privilege) {
                if ($privilege->getName() === $attribute) {
                    $granting[] = $assignment;
                    break;
                }
            }
        }

        if ($granting === []) {
            return false;
        }

        if (PrivilegeCatalog::isScoped($attribute)) {
            $departments = $this->resolveDepartments($subject);
            if ($departments !== []) {
                foreach ($granting as $assignment) {
                    $scope = $assignment->getDepartment();
                    if ($scope === null || \in_array($scope, $departments, true)) {
                        return true;
                    }
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Departments a resource belongs to, for scope checks.
     *
     * @return Department[]
     */
    private function resolveDepartments(mixed $subject): array
    {
        return match (true) {
            $subject instanceof Department => [$subject],
            $subject instanceof ShiftTask => array_filter([$subject->getDepartment()]),
            // A shift's own department is authoritative and always set; its shift
            // task is optional, so scoping through the task would leave task-less
            // shifts unscoped and grant every holder of the privilege.
            $subject instanceof Shift => array_filter([$subject->getDepartment()]),
            $subject instanceof VolunteerType => $subject->getDepartments()->toArray(),
            default => [],
        };
    }
}
