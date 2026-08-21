<?php

namespace App\Service\Statistics;

use App\Enum\ShiftAudience;

/**
 * The base numbers presented at the end of an event.
 *
 * Two independent splits run through this record, because "staff versus volunteers" means two
 * different things and the closing figures need both. Shifts split by {@see ShiftAudience}: what
 * the shift was offered as. People and hours split by whether the person holds a staff role. A
 * staff member who worked a public volunteer shift therefore appears under volunteer shifts and
 * under staff hours, which is correct for both readings and is labelled as such on the dashboard.
 */
final readonly class EventStatistics
{
    /**
     * @param array<string, int>   $shiftsByAudience    audience value => published shift count
     * @param array<string, float> $shiftHoursByAudience audience value => scheduled hours
     */
    public function __construct(
        public EventWindow $window,
        public \DateTimeImmutable $generatedAt,
        public int $shiftsPublished,
        public int $shiftsDraft,
        public array $shiftsByAudience,
        public array $shiftHoursByAudience,
        public int $shiftsVolunteerAudience,
        public int $shiftsStaffAudience,
        public float $shiftHoursScheduled,
        public int $slotsNeeded,
        public int $slotsFilled,
        public int $usersRegistered,
        public int $usersStaff,
        public int $usersActive,
        public int $usersActiveStaff,
        public int $usersActiveVolunteer,
        public HoursTotals $planned,
        public HoursTotals $worked,
        public HoursTotals $workedStaff,
        public HoursTotals $workedVolunteer,
        public float $dutyHours,
        public float $worklogHours,
        public int $entriesTotal,
        public int $entriesAssignment,
        public int $entriesApplication,
        public int $noshows,
        public int $departmentsWithShifts,
        public int $locationsUsed,
        public float $longestSingleShiftHours,
        public ?string $busiestDepartment,
    ) {
    }

    /** Share of requested slots that ended up filled, 0.0 when nothing was requested. */
    public function fillRate(): float
    {
        return $this->slotsNeeded > 0 ? $this->slotsFilled / $this->slotsNeeded : 0.0;
    }

    /** Mean worked wall-clock hours per person who did at least one shift. */
    public function averageWorkedHoursPerPerson(): float
    {
        return $this->usersActive > 0 ? $this->worked->raw / $this->usersActive : 0.0;
    }

    /** Share of registered accounts that worked at least one shift in the window. */
    public function participationRate(): float
    {
        return $this->usersRegistered > 0 ? $this->usersActive / $this->usersRegistered : 0.0;
    }
}
