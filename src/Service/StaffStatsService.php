<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\GoodieDistributionRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserRepository;

/**
 * Aggregates per-user and team statistics for the staff suite, reusing the
 * existing hours/goodies services. Computed live - there is no cache table.
 */
final class StaffStatsService
{
    public function __construct(
        private readonly DutyService $duty,
        private readonly HoursCalculator $hours,
        private readonly ShiftEntryRepository $entries,
        private readonly GoodieDistributionRepository $distributions,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return array{
     *     dutyHours: float, shiftHours: float, goodies: int,
     *     currentDuty: ?\App\Entity\DutyRecord, shiftEntries: int
     * }
     */
    public function userStats(User $user): array
    {
        return [
            'dutyHours' => $this->duty->totalDutyHours($user),
            'shiftHours' => $this->hours->totalForUser($user),
            'goodies' => $this->distributions->totalQuantityForUser($user),
            'currentDuty' => $this->duty->getCurrentDuty($user),
            'shiftEntries' => \count($this->entries->findByUserOrdered($user)),
        ];
    }

    /**
     * One summary row per user for the team dashboard.
     *
     * @return list<array{user: User, currentDuty: ?\App\Entity\DutyRecord, dutyHours: float, shiftHours: float, goodies: int}>
     */
    public function teamStats(): array
    {
        $rows = [];
        foreach ($this->users->findBy([], ['name' => 'ASC']) as $user) {
            $rows[] = [
                'user' => $user,
                'currentDuty' => $this->duty->getCurrentDuty($user),
                'dutyHours' => $this->duty->totalDutyHours($user),
                'shiftHours' => $this->hours->totalForUser($user),
                'goodies' => $this->distributions->totalQuantityForUser($user),
            ];
        }

        return $rows;
    }
}
