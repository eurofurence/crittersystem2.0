<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\EventConfigStore;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * The event-wide "Access mode" gate (configured at /manage/event-config).
 *
 * Access mode restricts who may use the system while an event is locked down:
 *  - public : any signed-in user
 *  - staff  : ROLE_STAFF and above
 *  - admin  : ROLE_SUBADMIN and above ("Admin only" also admits sub-admins)
 *
 * The required role is resolved through the role hierarchy, so ROLE_ADMIN satisfies
 * every mode — an administrator can never lock themselves out by tightening the gate.
 *
 * This only decides *whether* a user qualifies; enforcement (logging out and
 * redirecting non-qualifying users) lives in {@see \App\EventSubscriber\AccessModeGateSubscriber}.
 */
final class AccessModeGate
{
    public function __construct(
        private readonly EventConfigStore $config,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    public function mode(): string
    {
        $mode = $this->config->getString(EventConfigStore::KEY_ACCESS_MODE, 'public');

        return \in_array($mode, EventConfigStore::ACCESS_MODES, true) ? $mode : 'public';
    }

    /** True when the system is restricted to some subset of users (anything other than public). */
    public function isRestricted(): bool
    {
        return $this->mode() !== 'public';
    }

    /** Minimum coarse role a user must reach to use the system in the current mode. */
    public function requiredRole(): string
    {
        return match ($this->mode()) {
            'staff' => 'ROLE_STAFF',
            'admin' => 'ROLE_SUBADMIN',
            default => 'ROLE_USER',
        };
    }

    public function permits(User $user): bool
    {
        $reachable = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        return \in_array($this->requiredRole(), $reachable, true);
    }
}
