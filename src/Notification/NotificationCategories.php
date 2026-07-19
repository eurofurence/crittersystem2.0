<?php

namespace App\Notification;

/**
 * Catalogue of notification categories. Each category declares:
 *   - a human label;
 *   - `system`: in-app delivery is mandatory and cannot be disabled;
 *   - `inAppOnly`: never routed to email/Telegram (e.g. the Info Desk queue).
 */
final class NotificationCategories
{
    public const SHIFT_REMINDER = 'shift_reminder';
    public const SHIFT_ASSIGNMENT = 'shift_assignment';
    public const CALL_FOR_HELP = 'call_for_help';
    public const MESSAGE = 'message';
    public const CHECKIN_REQUIRED = 'checkin_required';
    public const SECURITY = 'security';
    public const INFO_DESK = 'info_desk';
    public const MANAGER_ALERT = 'manager_alert';
    public const GENERAL = 'general';

    /** @var array<string, array{0: string, 1: bool, 2: bool}> category => [label, system, inAppOnly] */
    public const CATEGORIES = [
        self::SHIFT_REMINDER => ['Shift start reminder', false, false],
        self::SHIFT_ASSIGNMENT => ['Shift assignment change', false, false],
        self::CALL_FOR_HELP => ['Global call for help', false, false],
        self::MESSAGE => ['New internal message', true, false],
        self::CHECKIN_REQUIRED => ['Required check-in', true, false],
        self::SECURITY => ['Security event', true, false],
        self::INFO_DESK => ['Info Desk support', true, true],
        // Operational alerts to the manager of a shift (e.g. it is understaffed).
        // Distinct from CALL_FOR_HELP, which is a broadcast asking volunteers to
        // step in: a manager must be able to mute that broadcast without also
        // losing the alerts about their own shifts.
        self::MANAGER_ALERT => ['Manager alert', false, false],
        self::GENERAL => ['General', false, false],
    ];

    public static function isValid(string $category): bool
    {
        return isset(self::CATEGORIES[$category]);
    }

    public static function label(string $category): string
    {
        return self::CATEGORIES[$category][0] ?? $category;
    }

    public static function isSystem(string $category): bool
    {
        return self::CATEGORIES[$category][1] ?? false;
    }

    public static function isInAppOnly(string $category): bool
    {
        return self::CATEGORIES[$category][2] ?? false;
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::CATEGORIES);
    }
}
