<?php

namespace App\Service\Board;

/**
 * How the department's volunteers spread across the credited-hours bands.
 *
 * Descriptive, not a judgement: it answers "is the load evenly shared" and nothing about whether any
 * particular figure is too high. That is why the chart uses one hue stepped light to dark rather
 * than a good-to-bad ramp, and why the band identity lives in the legend rather than in the colour.
 */
final class WorkloadDistribution
{
    /** @param list<WorkloadBand> $bands */
    private function __construct(
        public readonly array $bands,
        public readonly int $total,
    ) {
    }

    public static function build(BoardContext $context): self
    {
        $boundaries = $context->settings->workloadBands();

        $counts = array_fill(0, \count($boundaries) + 1, 0);
        $total = 0;
        foreach ($context->users as $user) {
            ++$total;
            $hours = $context->totalHoursFor($user);

            $index = \count($boundaries);
            foreach ($boundaries as $position => $boundary) {
                if ($hours < $boundary) {
                    $index = $position;
                    break;
                }
            }
            ++$counts[$index];
        }

        $bands = [];
        foreach ($counts as $index => $count) {
            $bands[] = new WorkloadBand(
                $index === 0 ? null : $boundaries[$index - 1],
                $boundaries[$index] ?? null,
                $count,
                $total > 0 ? $count / $total : 0.0,
                self::shade($index, \count($counts)),
            );
        }

        return new self($bands, $total);
    }

    /**
     * A single hue stepped light to dark by band - the ordinal encoding, so more hours reads as
     * more ink and no step implies "bad". Computed rather than listed so a department configuring
     * four or six bands is coloured as consistently as one keeping the default.
     *
     * At the default four bands this yields #b5cfee, #6a9fdc, #2c6eba, #1a4270, which clears the
     * adjacent-step separation thresholds for both normal and colour-deficient vision. Those are the
     * thresholds that matter here, because the bands sit against each other in a single stacked bar
     * rather than each against the page.
     *
     * The darkest step is deliberately below the 3:1 contrast target against the card. Pulling it
     * lighter compresses the whole ramp and drops the separation between the steps below it under
     * the readable floor, which is the worse failure of the two. It is relieved instead by the
     * legend that always accompanies the bar, naming every band with its count and its share - so
     * neither a segment's size nor its identity is ever carried by colour alone.
     */
    private static function shade(int $index, int $bandCount): string
    {
        $steps = max(1, $bandCount - 1);
        $lightness = 82 - (int) round(($index / $steps) * 55);

        return \sprintf('hsl(212 62%% %d%%)', $lightness);
    }
}
