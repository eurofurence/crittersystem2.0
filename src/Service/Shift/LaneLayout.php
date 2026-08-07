<?php

namespace App\Service\Shift;

/**
 * Side-by-side placement for time blocks that overlap.
 *
 * Shared by every grid that draws shifts against a time axis, so the planner and the staff
 * application screen cannot disagree about which shifts run in parallel.
 *
 * Blocks are grouped into clusters of transitively overlapping items, and each cluster is divided
 * into as many lanes as it needs. Clustering matters: two shifts overlapping each other must not
 * narrow a third that merely runs later. Touching is not overlapping - one ending at 22:00 and one
 * starting at 22:00 each keep a full lane - which is why the comparisons are strict.
 *
 * The lane count returned is the whole column's, not the cluster's: every block is sized against the
 * busiest moment so the column sits on one lane grid from top to bottom.
 */
final class LaneLayout
{
    /**
     * @param list<array<string, mixed>> $items each carrying startMin and endMin, in a stable order
     *
     * @return array{0: list<array<string, mixed>>, 1: int} the items with a lane, and the lane count
     */
    public function assign(array $items): array
    {
        $placed = [];
        $lanes = 1;

        foreach ($this->clusters($items) as $cluster) {
            /** @var int[] $laneEnds end minute of the last block placed in each lane */
            $laneEnds = [];
            foreach ($cluster as $i => $item) {
                $lane = null;
                foreach ($laneEnds as $index => $endsAt) {
                    if ($endsAt <= $item['startMin']) {
                        $lane = $index;
                        break;
                    }
                }
                if ($lane === null) {
                    $lane = \count($laneEnds);
                }
                $laneEnds[$lane] = $item['endMin'];
                $cluster[$i]['lane'] = $lane;
            }

            $lanes = max($lanes, \count($laneEnds));
            foreach ($cluster as $item) {
                $placed[] = $item;
            }
        }

        return [$placed, $lanes];
    }

    /**
     * Split blocks (already in start order) into runs of transitively overlapping items.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<list<array<string, mixed>>>
     */
    private function clusters(array $items): array
    {
        $clusters = [];
        $current = [];
        $clusterEnd = null;

        foreach ($items as $item) {
            if ($current !== [] && $clusterEnd !== null && $item['startMin'] >= $clusterEnd) {
                $clusters[] = $current;
                $current = [];
                $clusterEnd = null;
            }
            $current[] = $item;
            $clusterEnd = max($clusterEnd ?? $item['endMin'], $item['endMin']);
        }

        if ($current !== []) {
            $clusters[] = $current;
        }

        return $clusters;
    }
}
