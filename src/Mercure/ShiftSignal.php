<?php

namespace App\Mercure;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\User;

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
     * @param User|null $user the volunteer whose assignment changed, if one did: their operational
     *                        status is derived from their assignments, so their widget has a new
     *                        shift boundary to wait for
     */
    public function staffingChanged(Shift $shift, ?User $user = null): void
    {
        $topics = [];

        // A shift's own department is authoritative and always set. Scoping through its optional
        // shift task instead would leave task-less shifts unaddressed.
        if (($department = $shift->getDepartment()) !== null) {
            $topics[] = Topics::departmentShifts($department);
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
