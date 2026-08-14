<?php

namespace App\Security;

/**
 * Canonical catalogue of permissions and seeded permission groups.
 *
 * Single source of truth shared by:
 *  - the seeder (creates these permissions/groups and links them),
 *  - the PrivilegeVoter (decides which attributes are permission checks and how
 *    they are scoped), and
 *  - the group administration UI (categories, role level, step-up indicator).
 *
 * Permission names follow the `<domain>:<action>` standard. Groups are keyed by
 * a stable slug; their numeric IDs are database-assigned, never hard-coded.
 *
 * Each permission carries:
 *  - level: LEVEL_ADMIN  -> only assignable/visible to global admins,
 *           LEVEL_SUBADMIN -> also available to sub admins,
 *  - twoFactor: true when exercising it requires step-up authentication.
 */
final class PrivilegeCatalog
{
    public const LEVEL_ADMIN = 'admin';
    public const LEVEL_SUBADMIN = 'subadmin';

    /** The super-permission: holding it satisfies every check. */
    public const SUPER = 'global:admin';

    /**
     * Permissions that are scoped to a department when checked against a
     * resource subject. Without a subject they behave like ordinary checks.
     *
     * @var string[]
     */
    public const SCOPED = [
        'department:manage',
        'shift:manage',
        'shift:assign',
        'assignment:manage',
        'volunteertype:assign',
        'board:view',
    ];

    /**
     * name => [description, category, level, twoFactor].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public const PERMISSIONS = [
        // Core / global
        'global:admin' => ['Full, unrestricted administrative access', 'Core', self::LEVEL_ADMIN, false],
        'global:dashboard' => ['Access the management dashboard', 'Core', self::LEVEL_SUBADMIN, false],

        // Audit & forensics
        'audit:view' => ['View the audit log', 'Audit', self::LEVEL_ADMIN, true],
        'audit:export' => ['Generate a legal audit export', 'Audit', self::LEVEL_ADMIN, true],

        // Configuration
        'config:event' => ['Manage event configuration', 'Configuration', self::LEVEL_ADMIN, false],
        'config:display' => ['Manage timezone and date/time formats', 'Configuration', self::LEVEL_ADMIN, false],
        'config:theme' => ['Manage the default theme', 'Configuration', self::LEVEL_ADMIN, false],
        'config:privacy' => ['Manage the privacy notice', 'Configuration', self::LEVEL_ADMIN, false],
        'config:consent' => ['Manage consent texts', 'Configuration', self::LEVEL_ADMIN, false],
        'config:sso' => ['Manage SSO and view the connection status', 'Configuration', self::LEVEL_ADMIN, true],
        'config:telegram' => ['Manage the Telegram bot configuration', 'Configuration', self::LEVEL_ADMIN, false],

        // Security operations. Step-up guarded: releasing a lockout is the one action that can hand
        // an in-progress brute force its allowance back.
        'security:lockout:manage' => ['View and lift login lockouts', 'Access control', self::LEVEL_ADMIN, true],

        // Roles & access control
        'rbac:group:view' => ['View groups and permissions', 'Access control', self::LEVEL_SUBADMIN, false],
        'rbac:group:manage' => ['Create/edit groups and assign permissions', 'Access control', self::LEVEL_ADMIN, true],
        // Step-up guarded because the mapping screens display raw identity-provider group IDs.
        'rbac:ssomap:manage' => ['Manage SSO group mappings', 'Access control', self::LEVEL_ADMIN, true],

        // User management
        'user:view' => ['View users', 'Users', self::LEVEL_SUBADMIN, false],
        'user:create' => ['Create users and send invites', 'Users', self::LEVEL_SUBADMIN, false],
        'user:edit' => ['Edit user profiles', 'Users', self::LEVEL_SUBADMIN, false],
        'user:delete' => ['Delete or deactivate users', 'Users', self::LEVEL_ADMIN, false],
        'user:promote' => ['Assign groups and roles to users', 'Users', self::LEVEL_SUBADMIN, true],
        'user:pii:view' => ['View unmasked personal data', 'Users', self::LEVEL_ADMIN, true],
        'user:preseed' => ['Bulk pre-seed users from an identity-provider dump', 'Users', self::LEVEL_ADMIN, true],
        'user:arrive' => ['Check users in / mark as arrived', 'Users', self::LEVEL_SUBADMIN, false],
        'user:locate' => ['Locate a user at the info desk (exact email, registration number, or badge scan)', 'Users', self::LEVEL_SUBADMIN, false],
        'user:contact:view' => ["View a volunteer's consented contact details", 'Users', self::LEVEL_SUBADMIN, false],
        'user:worklog:edit' => ['Edit user worklog hours', 'Users', self::LEVEL_SUBADMIN, false],
        'profile:view' => ['View any user profile', 'Users', self::LEVEL_SUBADMIN, false],
        'profile:history:view' => ["View another user's shift history", 'Users', self::LEVEL_SUBADMIN, false],
        'worklog:self' => ['Record own worklog hours', 'Users', self::LEVEL_SUBADMIN, false],
        'department:member:manage' => ['Promote or remove department members', 'Organisation', self::LEVEL_SUBADMIN, false],
        'delegated:approve' => ['Approve delegated shift-manager requests', 'Organisation', self::LEVEL_SUBADMIN, false],
        'user:onboarding:manage' => ['Trigger or reset user onboarding', 'Users', self::LEVEL_ADMIN, false],
        'user:telegram:admin' => ["Unlink another user's Telegram account", 'Users', self::LEVEL_SUBADMIN, false],

        // Badges
        'badge:manage' => ['Create and edit badges', 'Badges', self::LEVEL_SUBADMIN, false],
        'badge:assign' => ['Assign or remove badges', 'Badges', self::LEVEL_SUBADMIN, false],

        // Shifts
        'manageshifts:view' => ['Access the staff Shift Manager module', 'Shifts', self::LEVEL_SUBADMIN, false],
        'shift:view' => ['Browse shifts', 'Shifts', self::LEVEL_SUBADMIN, false],
        'shift:apply' => ['Sign up for shifts', 'Shifts', self::LEVEL_SUBADMIN, false],
        'shift:self' => ['View own shifts', 'Shifts', self::LEVEL_SUBADMIN, false],
        'shift:manage' => ['Create and edit shifts', 'Shifts', self::LEVEL_SUBADMIN, false],
        'shift:publish' => ['Publish draft shifts', 'Shifts', self::LEVEL_SUBADMIN, false],
        'shift:assign' => ['Assign or remove users on shifts', 'Shifts', self::LEVEL_SUBADMIN, false],
        'shift:import' => ['Import schedules', 'Shifts', self::LEVEL_SUBADMIN, false],
        'invite:manage' => ['Create availability requests and shift invitation links', 'Shifts', self::LEVEL_SUBADMIN, false],
        'assignment:manage' => ['Assign and remove users on shifts and positions', 'Shifts', self::LEVEL_SUBADMIN, false],
        'assignment:override' => ['Override availability or hour warnings when assigning', 'Shifts', self::LEVEL_SUBADMIN, false],
        'board:view' => ['Open the live operations board for a department', 'Shifts', self::LEVEL_SUBADMIN, false],

        // Organisation
        'department:view' => ['View departments', 'Organisation', self::LEVEL_SUBADMIN, false],
        'department:manage' => ['Create and edit departments', 'Organisation', self::LEVEL_SUBADMIN, false],
        'location:view' => ['View locations', 'Organisation', self::LEVEL_SUBADMIN, false],
        'location:manage' => ['Create and edit locations', 'Organisation', self::LEVEL_SUBADMIN, false],
        'volunteertype:view' => ['View volunteer types', 'Organisation', self::LEVEL_SUBADMIN, false],
        'volunteertype:manage' => ['Create and edit volunteer types', 'Organisation', self::LEVEL_SUBADMIN, false],
        'volunteertype:assign' => ['Assign volunteer types to users', 'Organisation', self::LEVEL_SUBADMIN, false],

        // Certifications
        'certification:view' => ['View certifications', 'Certifications', self::LEVEL_SUBADMIN, false],
        'certification:apply' => ['Apply for or self-confirm certifications', 'Certifications', self::LEVEL_SUBADMIN, false],
        'certification:manage' => ['Create and edit certifications', 'Certifications', self::LEVEL_SUBADMIN, false],
        'certification:approve' => ['Approve or revoke user certifications', 'Certifications', self::LEVEL_SUBADMIN, false],

        // Goodies
        'goodie:view' => ['View goodies and eligibility', 'Goodies', self::LEVEL_SUBADMIN, false],
        'goodie:distribute' => ['Hand out goodies', 'Goodies', self::LEVEL_SUBADMIN, false],
        'goodie:manage' => ['Manage goodie categories and items', 'Goodies', self::LEVEL_SUBADMIN, false],

        // Content & communication
        'news:view' => ['View news', 'Content', self::LEVEL_SUBADMIN, false],
        'news:manage' => ['Create and edit news', 'Content', self::LEVEL_SUBADMIN, false],
        'faq:view' => ['View the FAQ', 'Content', self::LEVEL_SUBADMIN, false],
        'faq:manage' => ['Edit the FAQ', 'Content', self::LEVEL_SUBADMIN, false],
        'question:ask' => ['Ask questions', 'Content', self::LEVEL_SUBADMIN, false],
        'question:answer' => ['Answer questions', 'Content', self::LEVEL_SUBADMIN, false],
        'message:use' => ['Use private messaging', 'Content', self::LEVEL_SUBADMIN, false],
        'chat:claim' => ['Claim and answer Info Desk support conversations', 'Content', self::LEVEL_SUBADMIN, false],
        'chat:join' => ['Join a conversation claimed by another user', 'Content', self::LEVEL_ADMIN, false],
        'chat:restricted' => ['Send links and images in chat', 'Content', self::LEVEL_SUBADMIN, false],
        'call:trigger' => ['Trigger a global call for help', 'Content', self::LEVEL_SUBADMIN, false],
        'call:cancel' => ['Cancel a global call for help', 'Content', self::LEVEL_SUBADMIN, false],
        'call:respond' => ['Respond to a global call for help', 'Content', self::LEVEL_SUBADMIN, false],
        'meeting:view' => ['View meetings', 'Content', self::LEVEL_SUBADMIN, false],

        // Backstage & exports
        'backstage:view' => ['View the backstage dashboard', 'Backstage', self::LEVEL_SUBADMIN, false],
        'backstage:admin' => ['Full backstage access', 'Backstage', self::LEVEL_SUBADMIN, false],
        'export:ical' => ['Export the iCal feed', 'Exports', self::LEVEL_SUBADMIN, false],
        'export:atom' => ['Export the Atom feed', 'Exports', self::LEVEL_SUBADMIN, false],
        'export:shifts' => ['Export shifts as JSON', 'Exports', self::LEVEL_SUBADMIN, false],

        // Telegram (self-service link)
        'telegram:link' => ['Link or unlink own Telegram account', 'Telegram', self::LEVEL_SUBADMIN, false],
    ];

    /**
     * Permissions every signed-in user holds via the Volunteer group.
     *
     * volunteertype:view and location:view are part of the baseline: both pages
     * are open to any signed-in user, and the navigation gates its entries on
     * these privileges - without them a volunteer has no way to reach the page
     * where volunteer types are joined.
     *
     * @var string[]
     */
    public const VOLUNTEER = [
        'shift:view', 'shift:apply', 'shift:self', 'call:respond', 'news:view', 'faq:view',
        'question:ask', 'message:use', 'meeting:view', 'certification:view',
        'certification:apply', 'export:ical', 'export:atom', 'telegram:link',
        'volunteertype:view', 'location:view',
    ];

    /**
     * slug => [name, role, permissions[]]. A `permissions` value of '*subadmin*'
     * is expanded by the seeder to every sub-admin-level permission.
     *
     * @var array<string, array{name: string, role: ?string, permissions: string[]|string}>
     */
    public const GROUPS = [
        'global-admin' => [
            'name' => 'Global admin',
            'role' => 'ROLE_ADMIN',
            'permissions' => ['global:admin'],
        ],
        'sub-admin' => [
            'name' => 'Sub admin',
            'role' => 'ROLE_SUBADMIN',
            'permissions' => '*subadmin*',
        ],
        'volunteer' => [
            'name' => 'Volunteer',
            'role' => null,
            'permissions' => self::VOLUNTEER,
        ],
        'shift-manager' => [
            'name' => 'Shift manager',
            'role' => 'ROLE_STAFF',
            'permissions' => [
                'news:view',
                'manageshifts:view', 'board:view', 'shift:manage', 'shift:publish', 'shift:assign', 'shift:import', 'shift:view', 'shift:self', 'invite:manage', 'assignment:manage', 'assignment:override', 'call:trigger', 'call:cancel',
                'location:view', 'volunteertype:view', 'user:view', 'user:arrive',
                'profile:view', 'profile:history:view', 'worklog:self',
            ],
        ],
        'shift-manager-delegated' => [
            'name' => 'Shift manager (delegated)',
            'role' => 'ROLE_STAFF',
            'permissions' => [
                'news:view',
                'manageshifts:view', 'board:view', 'shift:manage', 'shift:publish', 'shift:assign', 'shift:import', 'shift:view', 'shift:self', 'invite:manage', 'assignment:manage', 'assignment:override', 'call:trigger', 'call:cancel',
                'location:view', 'volunteertype:view', 'user:view', 'user:arrive',
                'profile:view', 'profile:history:view', 'worklog:self',
            ],
        ],
        'department-manager' => [
            'name' => 'Department manager',
            'role' => 'ROLE_STAFF',
            'permissions' => [
                'news:view',
                'manageshifts:view', 'board:view', 'department:view', 'department:manage', 'shift:manage', 'shift:publish', 'shift:assign', 'invite:manage', 'assignment:manage', 'assignment:override', 'call:trigger', 'call:cancel',
                'volunteertype:view', 'volunteertype:assign', 'user:view', 'user:arrive',
                'profile:view', 'profile:history:view', 'worklog:self',
                'department:member:manage', 'delegated:approve',
            ],
        ],
        /*
         * Membership in a department is an active department-scoped assignment, so plain staff need a
         * group to be assigned. This is that group: it confers no management rights, only the staff
         * role and the read access a department member is expected to have.
         */
        'department-staff' => [
            'name' => 'Department staff',
            'role' => 'ROLE_STAFF',
            'permissions' => [
                'news:view',
                'manageshifts:view', 'department:view', 'shift:view', 'shift:apply', 'shift:self',
                'location:view', 'volunteertype:view', 'worklog:self',
            ],
        ],
        'info-desk' => [
            'name' => 'Info Desk',
            'role' => 'ROLE_STAFF',
            'permissions' => [
                'manageshifts:view', 'board:view', 'user:view', 'user:arrive', 'user:locate', 'user:contact:view', 'message:use', 'chat:claim', 'chat:restricted', 'call:trigger', 'call:cancel', 'shift:view', 'shift:assign',
                'goodie:view', 'goodie:distribute', 'certification:view', 'news:view', 'faq:view',
                'profile:view', 'profile:history:view', 'worklog:self',
            ],
        ],
        'communications-manager' => [
            'name' => 'Communications Manager',
            'role' => 'ROLE_STAFF',
            'permissions' => ['manageshifts:view', 'news:manage', 'news:view', 'faq:manage', 'faq:view', 'question:answer'],
        ],
        'certification-manager' => [
            'name' => 'Certification Manager',
            'role' => 'ROLE_STAFF',
            'permissions' => [
                'news:view','manageshifts:view', 'certification:manage', 'certification:approve', 'certification:view', 'certification:apply'],
        ],
        'goodies-manager' => [
            'name' => 'Goodies Manager',
            'role' => 'ROLE_STAFF',
            'permissions' => [
                'news:view','manageshifts:view', 'goodie:manage', 'goodie:distribute', 'goodie:view', 'backstage:view', 'user:locate'],
        ],
        'goodies-staff' => [
            'name' => 'Goodies Staff',
            'role' => 'ROLE_STAFF',
            'permissions' => [
                'news:view','manageshifts:view', 'goodie:distribute', 'goodie:view', 'backstage:view', 'user:locate'],
        ],
    ];

    public static function isPrivilege(string $name): bool
    {
        return \array_key_exists($name, self::PERMISSIONS);
    }

    public static function isScoped(string $name): bool
    {
        return \in_array($name, self::SCOPED, true);
    }

    public static function description(string $name): string
    {
        return self::PERMISSIONS[$name][0] ?? $name;
    }

    public static function category(string $name): string
    {
        return self::PERMISSIONS[$name][1] ?? 'Other';
    }

    public static function level(string $name): string
    {
        return self::PERMISSIONS[$name][2] ?? self::LEVEL_ADMIN;
    }

    public static function requiresTwoFactor(string $name): bool
    {
        return self::PERMISSIONS[$name][3] ?? false;
    }

    /** @return string[] every sub-admin-assignable permission name */
    public static function subadminPermissions(): array
    {
        return array_keys(array_filter(
            self::PERMISSIONS,
            static fn (array $meta): bool => $meta[2] === self::LEVEL_SUBADMIN,
        ));
    }

    /**
     * Resolve a group's permission list, expanding the '*subadmin*' sentinel.
     *
     * @param string[]|string $permissions
     *
     * @return string[]
     */
    public static function expandPermissions(array|string $permissions): array
    {
        return $permissions === '*subadmin*' ? self::subadminPermissions() : $permissions;
    }
}
