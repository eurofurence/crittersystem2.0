<?php

namespace App\Enum;

/**
 * Who a shift is offered to. A shift has exactly one audience mode.
 * Staff-only shifts are never exposed to volunteers.
 */
enum ShiftAudience: string
{
    /** Visible to eligible volunteers; eligible staff may also be assigned. */
    case PUBLIC_VOLUNTEER = 'public_volunteer';

    /** Visible to eligible staff across departments, according to permissions. */
    case ALL_STAFF = 'all_staff';

    /** Visible to eligible staff in the owning department. */
    case DEPARTMENT_STAFF = 'department_staff';

    /** Visible only through explicit assignment or a valid invitation. */
    case INVITE_ONLY = 'invite_only';

    public function label(): string
    {
        return match ($this) {
            self::PUBLIC_VOLUNTEER => 'Public volunteer shift',
            self::ALL_STAFF => 'All-staff shift',
            self::DEPARTMENT_STAFF => 'Department-staff shift',
            self::INVITE_ONLY => 'Invite-only staff shift',
        };
    }

    /** Public shifts are the only audience volunteers ever see. */
    public function isPublic(): bool
    {
        return $this === self::PUBLIC_VOLUNTEER;
    }

    /** Every non-public audience is staff-only and hidden from volunteers. */
    public function isStaffOnly(): bool
    {
        return !$this->isPublic();
    }
}
