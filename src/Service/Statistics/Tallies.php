<?php

namespace App\Service\Statistics;

/**
 * The hand-counted figures for one event: the known catalog entries an admin filled in, any
 * free-form rows they added for something this event counted and no other will, and an optional
 * notional hourly rate.
 *
 * The rate has no default on purpose. Multiplying volunteer hours by a wage is a striking figure
 * and a loaded one, so it appears only when somebody deliberately types a rate in.
 */
final readonly class Tallies
{
    /**
     * @param array<string, float>                      $known  catalog slug => amount, blank entries absent
     * @param list<array{label: string, amount: float}> $custom
     */
    public function __construct(
        public array $known = [],
        public array $custom = [],
        public ?float $hourlyRate = null,
        public string $currency = 'EUR',
    ) {
    }

    public function get(string $slug): ?float
    {
        return $this->known[$slug] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->known === [] && $this->custom === [] && $this->hourlyRate === null;
    }
}
