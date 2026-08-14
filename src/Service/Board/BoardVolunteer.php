<?php

namespace App\Service\Board;

use App\Entity\User;

/**
 * One volunteer as the board shows them.
 *
 * Hours are the application's credited figures - shift durations with the night and no-show rules
 * applied - not time actually spent on site. That is what every other hours figure in the
 * application means, and what the sign-up warning measures against, so the board agreeing with it
 * matters more than the board being separately clever.
 *
 * Presence, by contrast, is real: `presentSince` and `presentMinutesToday` come from duty sessions
 * and check-ins, so a card can show somebody credited for a shift they are not standing in.
 */
final class BoardVolunteer
{
    public const STATUS_ON_DUTY = 'on_duty';
    public const STATUS_ARRIVING = 'arriving';
    public const STATUS_OFF = 'off';

    public function __construct(
        public readonly User $user,
        public readonly ?string $role,
        public readonly float $todayHours,
        public readonly float $totalHours,
        public readonly int $capHours,
        public readonly ?\DateTimeImmutable $presentSince,
        public readonly int $presentMinutesToday,
        public readonly int $shiftCount,
        public readonly ?\DateTimeImmutable $nextShiftAt,
        public readonly string $status,
    ) {
    }

    public function isPresent(): bool
    {
        return $this->presentSince !== null;
    }

    /** How full the load meter is, capped at 1 so somebody past the recommendation still renders. */
    public function capFraction(): float
    {
        if ($this->capHours <= 0) {
            return 0.0;
        }

        return min(1.0, $this->totalHours / $this->capHours);
    }

    /**
     * Which band the volunteer falls in, as an index into the configured boundaries. The template
     * uses it for the meter colour and the status dot, and always prints the hours beside them.
     *
     * @param list<float> $bands
     */
    public function band(array $bands): int
    {
        foreach ($bands as $index => $boundary) {
            if ($this->totalHours < $boundary) {
                return $index;
            }
        }

        return \count($bands);
    }

    /**
     * Most loaded first, so whoever is closest to the recommendation is the first person read.
     *
     * @param list<self> $volunteers
     *
     * @return list<self>
     */
    public static function rankByLoad(array $volunteers): array
    {
        usort($volunteers, static function (self $a, self $b): int {
            return ($b->totalHours <=> $a->totalHours)
                ?: ($b->todayHours <=> $a->todayHours)
                ?: strcasecmp($a->user->getName(), $b->user->getName());
        });

        return $volunteers;
    }
}
