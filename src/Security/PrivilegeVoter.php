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
    public function __construct(private readonly PrivilegeScopeResolver $scopes)
    {
    }

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

        // Null means "every department": ROLE_ADMIN and the global:admin super-privilege, a
        // sub-admin holding a sub-admin-level permission, or a granting group assignment that
        // carries no department scope. An empty array means the user does not hold it at all.
        $held = $this->scopes->departmentsFor($user, $attribute);
        if ($held === []) {
            return false;
        }

        if (!PrivilegeCatalog::isScoped($attribute)) {
            return true;
        }

        $departments = $this->resolveDepartments($subject);
        if ($departments === []) {
            // No subject to scope against, so this reads as "may reach this module at all".
            // Callers bound to one resource MUST pass it; see the class docblock.
            return true;
        }

        if ($held === null) {
            return true;
        }

        foreach ($held as $department) {
            if (\in_array($department, $departments, true)) {
                return true;
            }
        }

        return false;
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
