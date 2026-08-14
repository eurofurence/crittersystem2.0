<?php

namespace App\Service\Board;

/** One bucket of the workload distribution. A null bound is open-ended on that side. */
final class WorkloadBand
{
    public function __construct(
        public readonly ?float $from,
        public readonly ?float $to,
        public readonly int $count,
        public readonly float $share,
        public readonly string $colour,
    ) {
    }

    public function percent(): int
    {
        return (int) round($this->share * 100);
    }
}
