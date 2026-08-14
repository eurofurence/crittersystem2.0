<?php

namespace App\Service\Board;

use App\Entity\HelpCall;
use App\Entity\Shift;

/** One shift as the board shows it, with everything the row and the panels need already resolved. */
final class BoardShiftRow
{
    public function __construct(
        public readonly Shift $shift,
        public readonly int $needed,
        public readonly int $assigned,
        public readonly int $present,
        public readonly ShiftStatus $status,
        public readonly bool $isRunning,
        public readonly ?HelpCall $helpCall,
    ) {
    }

    public function shortfall(): int
    {
        return max(0, $this->needed - $this->assigned);
    }

    /** Minutes until the shift starts; negative once it has, null when it is over. */
    public function startsInMinutes(\DateTimeImmutable $now): ?int
    {
        if ($this->shift->getEndsAt() <= $now) {
            return null;
        }

        return intdiv($this->shift->getStartsAt()->getTimestamp() - $now->getTimestamp(), 60);
    }

    /**
     * Worst first: shifts already running and short of people before ones that only start later, and
     * within each, the bigger gap first.
     *
     * @param list<self> $rows
     *
     * @return list<self>
     */
    public static function rankForStaffing(array $rows): array
    {
        usort($rows, static function (self $a, self $b): int {
            return ($b->isRunning <=> $a->isRunning)
                ?: ($b->status->urgency() <=> $a->status->urgency())
                ?: ($b->shortfall() <=> $a->shortfall())
                ?: ($a->shift->getStartsAt() <=> $b->shift->getStartsAt());
        });

        return $rows;
    }
}
