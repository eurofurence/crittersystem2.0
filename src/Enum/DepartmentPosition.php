<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A user's position inside a department.
 *
 * A position is stored as a department-scoped {@see \App\Entity\UserGroupAssignment} whose group
 * carries the matching slug — there is no position column. This enum is the single place that maps
 * the two directions, so the slugs are not spelled out at call sites.
 *
 * `rank()` is the precedence used when several positions could apply at once (a user holding both
 * the SSO department-manager and shift-manager roles is a department manager).
 */
enum DepartmentPosition: string
{
    case MANAGER = 'manager';
    case SHIFT_MANAGER = 'shift_manager';
    case STAFF = 'staff';

    /**
     * A delegated shift manager is a shift manager for every display and permission purpose, but the
     * grant is time-boxed and approval-backed, so it is never written by a position change.
     */
    public const DELEGATED_SHIFT_MANAGER_SLUG = 'shift-manager-delegated';

    public function groupSlug(): string
    {
        return match ($this) {
            self::MANAGER => 'department-manager',
            self::SHIFT_MANAGER => 'shift-manager',
            self::STAFF => 'department-staff',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MANAGER => 'Department manager',
            self::SHIFT_MANAGER => 'Shift manager',
            self::STAFF => 'Department staff',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::MANAGER => 2,
            self::SHIFT_MANAGER => 1,
            self::STAFF => 0,
        };
    }

    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /** The position a group slug stands for, or null when the group says nothing about position. */
    public static function fromGroupSlug(string $slug): ?self
    {
        if ($slug === self::DELEGATED_SHIFT_MANAGER_SLUG) {
            return self::SHIFT_MANAGER;
        }

        foreach (self::cases() as $position) {
            if ($position->groupSlug() === $slug) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Group slugs that encode a position and may therefore be swapped when the position changes.
     * The delegated slug is excluded on purpose — see the constant above.
     *
     * @return string[]
     */
    public static function assignableSlugs(): array
    {
        return array_map(static fn (self $p): string => $p->groupSlug(), self::cases());
    }
}
