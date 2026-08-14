<?php

namespace App\Service\Board;

/**
 * How the next few hours look: what the schedule asks for against who is assigned to cover it.
 *
 * "Needed" is the sum of every shift's required headcount over each bucket, and "planned" counts
 * distinct volunteers - somebody holding two overlapping shifts is one person, and counting them
 * twice would show a gap as covered.
 */
final class StaffingForecast
{
    /** @param list<ForecastBucket> $buckets */
    private function __construct(public readonly array $buckets)
    {
    }

    public static function build(BoardContext $context): self
    {
        $step = $context->settings->forecastStepHours();
        $horizon = $context->settings->forecastHorizonHours();

        // Anchored to the running hour so the first column really is "now" rather than a boundary
        // that has already passed.
        $anchor = $context->now->setTime((int) $context->now->format('H'), 0);

        $buckets = [];
        for ($offset = 0; $offset <= $horizon; $offset += $step) {
            $from = $anchor->modify(\sprintf('+%d hours', $offset));
            $to = $from->modify(\sprintf('+%d hours', $step));

            $needed = 0;
            $planned = [];
            foreach ($context->shifts as $shift) {
                if ($shift->getStartsAt() >= $to || $shift->getEndsAt() <= $from) {
                    continue;
                }
                $needed += $context->neededFor($shift);
                foreach ($context->entriesFor($shift) as $entry) {
                    $planned[$entry->getUser()->getId()] = true;
                }
            }

            $buckets[] = new ForecastBucket($from, $needed, \count($planned), $offset === 0);
        }

        return new self($buckets);
    }

    /** The next bucket boundary, so the board re-renders as the "now" column rolls over. */
    public function nextBoundary(\DateTimeImmutable $now, int $stepHours): \DateTimeImmutable
    {
        return $now->setTime((int) $now->format('H'), 0)->modify(\sprintf('+%d hours', max(1, $stepHours)));
    }
}
