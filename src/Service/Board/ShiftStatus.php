<?php

namespace App\Service\Board;

/**
 * What a shift row says at a glance. Ordered worst first by {@see urgency()}, so the shifts panel
 * can put the ones needing somebody now above the ones that merely start later.
 */
enum ShiftStatus: string
{
    case Unassigned = 'unassigned';
    case Understaffed = 'understaffed';
    case Active = 'active';
    case Upcoming = 'upcoming';
    case Done = 'done';

    public static function of(BoardContext $context, \App\Entity\Shift $shift): self
    {
        $needed = $context->neededFor($shift);
        $assigned = $context->assignedFor($shift);
        $short = $needed > 0 && $assigned < $needed;

        if ($shift->getEndsAt() <= $context->now) {
            return self::Done;
        }

        if ($short) {
            return $assigned === 0 ? self::Unassigned : self::Understaffed;
        }

        return $context->isRunning($shift) ? self::Active : self::Upcoming;
    }

    public function urgency(): int
    {
        return match ($this) {
            self::Unassigned => 5,
            self::Understaffed => 4,
            self::Active => 3,
            self::Upcoming => 2,
            self::Done => 1,
        };
    }

    public function needsStaffing(): bool
    {
        return $this === self::Unassigned || $this === self::Understaffed;
    }
}
