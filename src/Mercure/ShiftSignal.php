<?php

namespace App\Mercure;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\User;
use App\Enum\ShiftAudience;

/**
 * "This shift's staffing changed."
 *
 * Several paths change it - a volunteer applies or withdraws, a manager assigns or unassigns, a
 * help call is answered - and every one of them has to reach the same screens: the apply rows, the
 * staffing page, the department grid and the planner. Keeping the decision here means a new path
 * cannot quietly forget one of them.
 *
 * Always a signal, never the row. Every one of those screens renders per viewer - eligibility,
 * overlaps, hours, PII in the assigned names - so what changed must not travel over the hub. Each
 * viewer re-requests and the server decides what they see.
 */
final class ShiftSignal
{
    public function __construct(private readonly UpdatePublisher $live)
    {
    }

    /**
     * The shift is addressed by its own department, which is authoritative and always set. Going
     * through its optional shift task instead would leave task-less shifts unaddressed.
     *
     * An all-staff shift also signals the all-staff topic: staff outside the owning department see
     * that row on their apply screen and subscribe to it there, rather than to the department,
     * which they are not entitled to watch as a whole.
     *
     * @param User|null $user the volunteer whose assignment changed, if one did: their operational
     *                        status is derived from their assignments, so their widget has a new
     *                        shift boundary to wait for
     */
    public function staffingChanged(Shift $shift, ?User $user = null): void
    {
        $topics = [];

        if (($department = $shift->getDepartment()) !== null) {
            $topics[] = Topics::departmentShifts($department);
        }
        if ($shift->getAudience() === ShiftAudience::ALL_STAFF) {
            $topics[] = Topics::allStaffShifts();
        }
        if ($user !== null) {
            $topics[] = Topics::userStatus($user);
        }

        $this->live->signal($topics);
    }

    /**
     * A change to the department's planning structure rather than to one shift: a position group,
     * the ordering of positions, a structure copied from another department.
     */
    public function departmentChanged(?Department $department): void
    {
        if ($department === null) {
            return;
        }

        $this->live->signal(Topics::departmentShifts($department));
    }
}
