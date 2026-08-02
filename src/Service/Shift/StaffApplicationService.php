<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\User;
use App\Repository\DepartmentRepository;
use App\Repository\ShiftRepository;
use App\Repository\UserGroupAssignmentRepository;
use App\Service\Assignment\EventHoursGuard;
use App\Service\Availability\AvailabilityService;
use App\Service\ShiftSignupService;

/**
 * Builds the staff shift application view: departments with open
 * staff shifts the user may apply to, grouped into those the user is a member of
 * and other viewable departments. Each shift carries its live capacity, the
 * user's eligibility status and their declared availability for the slot.
 */
final class StaffApplicationService
{
    public function __construct(
        private readonly DepartmentRepository $departments,
        private readonly ShiftRepository $shifts,
        private readonly UserGroupAssignmentRepository $memberships,
        private readonly ShiftVisibilityResolver $visibility,
        private readonly ShiftSignupService $signup,
        private readonly AvailabilityService $availability,
        private readonly EventHoursGuard $hoursGuard,
    ) {
    }

    /**
     * @return array{member: list<array{department: Department, shifts: list<array<string, mixed>>}>, other: list<array{department: Department, shifts: list<array<string, mixed>>}>}
     */
    public function departmentGroups(User $user): array
    {
        $member = [];
        $other = [];
        foreach ($this->departments->findAllOrdered() as $department) {
            if ($department->isOrganizational()) {
                continue;
            }
            $shifts = $this->applicableShifts($department, $user);
            if ($shifts === []) {
                continue;
            }
            $entry = ['department' => $department, 'shifts' => $shifts];
            if ($this->memberships->userIsMember($user, $department)) {
                $member[] = $entry;
            } else {
                $other[] = $entry;
            }
        }

        return ['member' => $member, 'other' => $other];
    }

    /** @return list<array<string, mixed>> the shift view models this user may see in the department */
    public function applicableShifts(Department $department, User $user): array
    {
        $rows = [];
        foreach ($this->shifts->findUpcomingStaffPublished() as $shift) {
            if ($shift->getDepartment() !== $department || !$this->visibility->isVisibleTo($shift, $user)) {
                continue;
            }
            $rows[] = $this->shiftRow($shift, $user);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function shiftRow(Shift $shift, User $user): array
    {
        $availability = $this->signup->availability($shift);
        $needed = array_sum(array_column($availability, 'needed'));
        $assigned = array_sum(array_column($availability, 'assigned'));

        return [
            'shift' => $shift,
            'status' => $this->signup->eligibilityStatus($shift, $user),
            'signupOptions' => $this->signup->signupOptions($shift, $user),
            'needed' => $needed,
            'assigned' => $assigned,
            'availability' => $this->availability->planningState($user, $shift->getStartsAt(), $shift->getEndsAt(), $shift),
            'overHours' => $this->hoursGuard->wouldExceed($user, $shift),
        ];
    }
}
