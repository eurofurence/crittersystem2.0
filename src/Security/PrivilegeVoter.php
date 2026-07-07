<?php

namespace App\Security;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\ShiftType;
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

        if ($user->hasPrivilege(PrivilegeCatalog::SUPER)) {
            return true;
        }

        // Collect the active assignments whose group grants this permission.
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
            $subject instanceof ShiftType => array_filter([$subject->getDepartment()]),
            $subject instanceof Shift => array_filter([$subject->getShiftType()?->getDepartment()]),
            $subject instanceof VolunteerType => $subject->getDepartments()->toArray(),
            default => [],
        };
    }
}
