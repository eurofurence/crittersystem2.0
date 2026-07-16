<?php

declare(strict_types=1);

namespace App\Sso;

use App\Entity\User;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grants the app-wide global-admin / sub-admin groups from two identity-provider role IDs.
 *
 * Unlike a department position, these grants are unscoped: holding the configured global-admin role
 * ID makes the user a global admin everywhere, and the sub-admin role ID a sub admin. Holding both
 * resolves to global admin — the higher role wins.
 *
 * Each of the two groups is only managed when its role ID is configured. That gate matters: with a
 * role ID set, the group is owned by the identity provider and reconciled on every sign-in (added
 * when the user holds the role, removed when they no longer do, so a demotion at the provider takes
 * effect on the next login). With the field left blank the app has no opinion on that group and never
 * touches it, so a membership granted by hand is left alone. Only unscoped memberships are touched;
 * a department-scoped assignment of the same group is never rewritten here.
 *
 * The slugs are the seeded groups in {@see \App\Security\PrivilegeCatalog::GROUPS}.
 */
final class SsoGlobalRoles
{
    private const SLUG_GLOBAL_ADMIN = 'global-admin';
    private const SLUG_SUB_ADMIN = 'sub-admin';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GroupRepository $groups,
        private readonly SsoRoleSettings $settings,
    ) {
    }

    /** @param string[] $ssoGroupIds the group/role IDs claimed by the identity provider */
    public function apply(User $user, array $ssoGroupIds): void
    {
        $this->reconcile($user, $this->resolve($ssoGroupIds));
    }

    /**
     * The single global group the claimed roles grant, or null for none.
     *
     * @param string[] $ssoGroupIds
     */
    public function resolve(array $ssoGroupIds): ?string
    {
        $globalAdminRole = $this->settings->globalAdminRole();
        $subAdminRole = $this->settings->subAdminRole();

        if ($globalAdminRole !== null && \in_array($globalAdminRole, $ssoGroupIds, true)) {
            return self::SLUG_GLOBAL_ADMIN;
        }
        if ($subAdminRole !== null && \in_array($subAdminRole, $ssoGroupIds, true)) {
            return self::SLUG_SUB_ADMIN;
        }

        return null;
    }

    private function reconcile(User $user, ?string $wantedSlug): void
    {
        // slug => whether the user should hold it, limited to the groups whose role ID is configured.
        $managed = [];
        if ($this->settings->globalAdminRole() !== null) {
            $managed[self::SLUG_GLOBAL_ADMIN] = $wantedSlug === self::SLUG_GLOBAL_ADMIN;
        }
        if ($this->settings->subAdminRole() !== null) {
            $managed[self::SLUG_SUB_ADMIN] = $wantedSlug === self::SLUG_SUB_ADMIN;
        }
        if ($managed === []) {
            return;
        }

        $held = [];
        foreach ($user->getGroupAssignments() as $assignment) {
            if ($assignment->getDepartment() !== null) {
                continue;
            }
            $slug = $assignment->getGroup()->getSlug();
            if (!\array_key_exists($slug, $managed)) {
                continue;
            }
            if ($managed[$slug]) {
                $held[$slug] = true;
            } else {
                $this->em->remove($assignment);
                $user->getGroupAssignments()->removeElement($assignment);
            }
        }

        foreach ($managed as $slug => $wanted) {
            if (!$wanted || isset($held[$slug])) {
                continue;
            }
            $group = $this->groups->findOneBySlug($slug);
            if ($group !== null) {
                $user->addGroup($group);
            }
        }
    }
}
