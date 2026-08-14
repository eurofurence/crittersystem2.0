<?php

namespace App\Service\Board;

/** One column of the staffing forecast. */
final class ForecastBucket
{
    public function __construct(
        public readonly \DateTimeImmutable $startsAt,
        public readonly int $needed,
        public readonly int $planned,
        public readonly bool $isNow,
    ) {
    }

    public function difference(): int
    {
        return $this->planned - $this->needed;
    }

    /**
     * Severity of the shortfall, as a proportion of what the hour asks for. A surplus and an exactly
     * covered hour read the same, because neither needs anybody to do anything.
     *
     * @return int 0 covered, rising to 3 for a serious gap
     */
    public function heat(): int
    {
        $difference = $this->difference();
        if ($difference >= 0 || $this->needed === 0) {
            return 0;
        }

        $shortfall = -$difference / $this->needed;

        return match (true) {
            $shortfall >= 0.5 => 3,
            $shortfall >= 0.25 => 2,
            default => 1,
        };
    }
}
