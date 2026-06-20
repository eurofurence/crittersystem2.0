<?php

namespace App\Security;

/**
 * Canonical catalogue of core groups and privileges.
 *
 * Single source of truth shared by:
 *  - the app:install seeder (creates these groups/privileges and links them), and
 *  - the PrivilegeVoter (decides which attributes are privilege checks).
 *
 * Privilege names follow the redesign specification. Group IDs are the
 * fixed well-known IDs
 */
final class PrivilegeCatalog
{
    /**
     * privilege name => human description.
     *
     * @var array<string, string>
     */
    public const PRIVILEGES = [
        // Navigation
        'start' => 'Access the landing page',
        'login' => 'Log in',
        'logout' => 'Log out',
        'register' => 'Register a new account',
        'news' => 'View news',
        // User management
        'admin_user' => 'Administer users',
        'user_settings' => 'Manage own settings',
        'user_messages' => 'Use private messaging',
        // Shift system
        'user_shifts' => 'Browse and sign up for shifts',
        'user_myshifts' => 'View own shifts',
        'admin_shifts' => 'Administer shifts',
        'user_shifts_admin' => 'Manage shift assignments',
        'admin_arrive' => 'Mark users as arrived',
        // Admin functions
        'admin_active' => 'Manage active state',
        'admin_free' => 'View free/available volunteers',
        'admin_rooms' => 'Administer locations',
        'admin_volunteer_types' => 'Administer volunteer types',
        'admin_groups' => 'Administer groups and privileges',
        'admin_news' => 'Administer news',
        // Type flags
        'user.type.staff' => 'Marked as staff',
        'user.type.internal_staff' => 'Marked as internal staff',
        'user.type.admin' => 'Marked as administrator',
        'admin' => 'Full administrative access',
        // Features
        'admin_user_worklog' => 'Manage user worklogs',
        'faq.view' => 'View the FAQ',
        'faq.edit' => 'Edit the FAQ',
        'question.add' => 'Ask questions',
        'question.edit' => 'Answer questions',
        // Resource edit
        'user.goodie.edit' => 'Edit user goodie state',
        'user.info.edit' => 'Edit user info notes',
        'user.fa.edit' => 'Edit first-aid info',
        'user.drive.edit' => 'Edit driving licenses',
        'user.ifsg.edit' => 'Edit food-hygiene certificates',
        'admin.arrive.list' => 'View the arrival list',
        // Events / config
        'schedule.import' => 'Import schedules',
        'user_meetings' => 'View meetings',
        'admin_language' => 'Manage languages',
        'admin_log' => 'View logs',
        'admin_event_config' => 'Manage event configuration',
        'admin_user_volunteertypes' => 'Manage user volunteer types',
        // Exports
        'ical' => 'Export iCal feed',
        'atom' => 'Export Atom feed',
        'shifts_json_export' => 'Export shifts as JSON',
        // Backstage
        'backstage.view' => 'View the backstage dashboard',
        'backstage.admin' => 'Full backstage access',
        'backstage.goodies.view' => 'View goodies distribution',
        'backstage.goodies.agent' => 'Distribute goodies',
        'backstage.goodies.admin' => 'Administer goodies items/categories',
    ];

    /**
     * Core groups keyed by fixed ID: [id => [name, slug, privileges[]]].
     *
     * @var array<int, array{name: string, slug: string, privileges: string[]}>
     */
    public const GROUPS = [
        10 => [
            'name' => 'Guest',
            'slug' => 'guest',
            'privileges' => ['start', 'login', 'register'],
        ],
        20 => [
            'name' => 'Volunteer',
            'slug' => 'volunteer',
            'privileges' => [
                'start', 'logout', 'news', 'user_settings', 'user_messages',
                'user_shifts', 'user_myshifts', 'user_meetings', 'ical', 'atom',
                'faq.view', 'question.add',
            ],
        ],
        30 => [
            'name' => 'Welcome Volunteer',
            'slug' => 'welcome-volunteer',
            'privileges' => ['start', 'logout', 'news', 'user_settings'],
        ],
        35 => [
            'name' => 'Voucher Volunteer',
            'slug' => 'voucher-volunteer',
            'privileges' => [],
        ],
        50 => [
            'name' => 'Goodies Manager',
            'slug' => 'goodies-manager',
            'privileges' => [
                'backstage.view', 'backstage.goodies.view', 'backstage.goodies.agent',
                'backstage.goodies.admin', 'user.goodie.edit',
            ],
        ],
        60 => [
            'name' => 'Shift Coordinator',
            'slug' => 'shift-coordinator',
            'privileges' => [
                'admin_shifts', 'user_shifts_admin', 'admin_arrive', 'admin_rooms',
                'admin_free', 'schedule.import', 'user.type.staff',
            ],
        ],
        65 => [
            'name' => 'Team Coordinator',
            'slug' => 'team-coordinator',
            'privileges' => [
                'admin_volunteer_types', 'admin_user_volunteertypes', 'user.type.staff',
            ],
        ],
        80 => [
            'name' => 'Bureaucrat',
            'slug' => 'bureaucrat',
            'privileges' => [
                'admin_user', 'admin_active', 'admin_user_worklog', 'user.info.edit',
                'user.drive.edit', 'user.ifsg.edit', 'user.fa.edit', 'admin.arrive.list',
                'admin_log', 'user.type.staff', 'user.type.internal_staff',
            ],
        ],
        85 => [
            'name' => 'News Admin',
            'slug' => 'news-admin',
            'privileges' => ['admin_news', 'news', 'question.edit', 'faq.edit'],
        ],
        90 => [
            'name' => 'Developer',
            'slug' => 'developer',
            'privileges' => [
                'admin', 'user.type.admin', 'user.type.staff', 'user.type.internal_staff',
                'admin_groups', 'admin_language', 'admin_event_config', 'admin_log',
            ],
        ],
    ];

    public static function isPrivilege(string $name): bool
    {
        return \array_key_exists($name, self::PRIVILEGES);
    }
}
