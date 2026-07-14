<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\Shift;
use App\Enum\ShiftEntryState;
use App\Repository\ShiftRepository;
use App\Service\ShiftSignupService;

/**
 * Builds the department shift management grid: all relevant shifts
 * with time, location, audience, fill status, assigned users and open positions.
 */
final class DepartmentGridService
{
    public function __construct(
        private readonly ShiftRepository $shifts,
        private readonly ShiftSignupService $signup,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function grid(Department $department, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->shifts->findForDepartmentBetween($department, $from, $to) as $shift) {
            $rows[] = $this->row($shift);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function row(Shift $shift): array
    {
        $availability = $this->signup->availability($shift);
        $needed = (int) array_sum(array_column($availability, 'needed'));
        $assigned = (int) array_sum(array_column($availability, 'assigned'));

        $assignedUsers = [];
        $applications = 0;
        foreach ($shift->getEntries() as $entry) {
            if ($entry->getState() === ShiftEntryState::ASSIGNMENT) {
                $assignedUsers[] = $entry->getUser();
            } else {
                ++$applications;
            }
        }

        $openPositions = 0;
        foreach ($shift->getShiftPositions() as $position) {
            if ($position->cellState() === 'open') {
                ++$openPositions;
            }
        }

        return [
            'shift' => $shift,
            'needed' => $needed,
            'assigned' => $assigned,
            'fillState' => $this->fillState($needed, $assigned),
            'assignedUsers' => $assignedUsers,
            'applications' => $applications,
            'openPositions' => $openPositions,
        ];
    }

    private function fillState(int $needed, int $assigned): string
    {
        if ($needed === 0) {
            return 'none';
        }

        return $assigned >= $needed ? 'full' : 'open';
    }
}
