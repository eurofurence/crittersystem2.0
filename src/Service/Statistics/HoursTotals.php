<?php

namespace App\Service\Statistics;

/**
 * A pair of hour figures for the same population, reported side by side because they answer
 * different questions and differ by a wide margin at any event that runs night shifts.
 *
 * `raw` is elapsed wall-clock time with each person's overlapping bookings merged, so it is the
 * honest answer to "how long were people actually here". `credited` is what the application rewards
 * everywhere else (night multiplier, no-show penalty), so it is the figure that matches a
 * volunteer's own profile page. Presenting only one of them invites a number on stage that
 * contradicts what people can read in their account.
 */
final readonly class HoursTotals
{
    public function __construct(
        public float $raw = 0.0,
        public float $credited = 0.0,
    ) {
    }

    public function plus(self $other): self
    {
        return new self($this->raw + $other->raw, $this->credited + $other->credited);
    }
}
