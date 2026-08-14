<?php

namespace App\Service\Board;

use App\Entity\Shift;
use App\Entity\User;

/**
 * A volunteer about to start, or one who has just finished.
 *
 * Both lists answer the same shape of question - who, doing what, at what moment - so they share a
 * type; the panel decides whether `at` reads as "in 12 min" or "40 min ago".
 */
final class BoardArrival
{
    public function __construct(
        public readonly User $user,
        public readonly ?string $role,
        public readonly \DateTimeImmutable $at,
        public readonly ?Shift $shift,
    ) {
    }

    /**
     * @param list<self> $arrivals
     *
     * @return list<self>
     */
    public static function soonestFirst(array $arrivals): array
    {
        usort($arrivals, static fn (self $a, self $b): int => $a->at <=> $b->at);

        return $arrivals;
    }

    /**
     * @param list<self> $arrivals
     *
     * @return list<self>
     */
    public static function mostRecentFirst(array $arrivals): array
    {
        usort($arrivals, static fn (self $a, self $b): int => $b->at <=> $a->at);

        return $arrivals;
    }
}
