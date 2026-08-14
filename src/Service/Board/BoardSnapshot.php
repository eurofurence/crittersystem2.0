<?php

namespace App\Service\Board;

use App\Entity\Department;
use App\Service\Board\Attention\AttentionItem;

/**
 * One department's day, as the board renders it.
 *
 * Every panel and every KPI is derived here, from one load of the data, so a number in a tile and
 * the list it summarises cannot disagree. The initial render and every live refresh build this the
 * same way, which is what keeps two boards showing the same thing.
 */
final class BoardSnapshot
{
    /**
     * @param list<AttentionItem>  $attention   ranked, most severe first
     * @param list<BoardVolunteer> $activeStaff everyone present now, most loaded first
     * @param list<BoardVolunteer> $staff       everyone the day involves, most loaded first
     * @param list<BoardShiftRow>  $shiftRows   the day's shifts, soonest first
     * @param list<BoardShiftRow>  $openShifts  those needing people, worst first
     * @param list<BoardArrival>   $comingNext
     * @param list<BoardArrival>   $recentlyOff
     */
    public function __construct(
        public readonly Department $department,
        public readonly \DateTimeImmutable $day,
        public readonly \DateTimeImmutable $now,
        public readonly int $activeCount,
        public readonly int $plannedCount,
        public readonly int $openPositions,
        public readonly int $activeShiftCount,
        public readonly ?BoardShiftRow $nextShift,
        public readonly array $attention,
        public readonly array $activeStaff,
        public readonly array $staff,
        public readonly array $shiftRows,
        public readonly array $openShifts,
        public readonly array $comingNext,
        public readonly array $recentlyOff,
        public readonly WorkloadDistribution $workload,
        public readonly StaffingForecast $forecast,
        public readonly ?\DateTimeImmutable $nextTransitionAt,
    ) {
    }

    public function totalShiftCount(): int
    {
        return \count($this->shiftRows);
    }

    public function attentionCount(): int
    {
        return \count($this->attention);
    }

    public function isEmpty(): bool
    {
        return $this->shiftRows === [] && $this->staff === [];
    }
}
