<?php

namespace App\Service\Board\Attention;

use App\Service\Board\BoardContext;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * A volunteer whose back-to-back assigned shifts add up to more than one stretch should be.
 *
 * A look-ahead: it reads the schedule rather than who is present, so it fires before anybody is
 * tired rather than after. Touching shifts count as one chain; a real gap between two of them ends
 * the chain, on the same reasoning as the continuous-presence rule.
 */
final class SequentialShiftsRule implements AttentionRule
{
    public function evaluate(BoardContext $context): array
    {
        $limit = $context->settings->maxSequentialMinutes();
        $items = [];

        foreach ($context->users as $user) {
            $chain = $this->longestChainTouchingDay($context, $user->getId());
            if ($chain === null) {
                continue;
            }

            [$start, $end] = $chain;
            $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
            if ($minutes <= $limit) {
                continue;
            }

            $items[] = new AttentionItem(
                'sequential_shifts',
                $minutes >= $limit * 1.5 ? AttentionSeverity::Serious : AttentionSeverity::Warning,
                'user:'.$user->getUuid(),
                new TranslatableMessage('board.attention.sequential.title', [
                    '%name%' => $user->getName(),
                    '%hours%' => \sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60),
                ]),
                new TranslatableMessage('board.attention.sequential.detail', [
                    '%limit%' => \sprintf('%dh', intdiv($limit, 60)),
                ]),
                $start,
            );
        }

        return $items;
    }

    /**
     * The longest run of touching shifts that overlaps the day being shown. Chains entirely on other
     * days belong on those days' boards, not on this one.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function longestChainTouchingDay(BoardContext $context, ?int $userId): ?array
    {
        $entries = $context->entriesByUser[$userId] ?? [];
        if ($entries === []) {
            return null;
        }

        $intervals = [];
        foreach ($entries as $entry) {
            if ($entry->isNoshow()) {
                continue;
            }
            $intervals[] = [$entry->getShift()->getStartsAt(), $entry->getShift()->getEndsAt()];
        }
        if ($intervals === []) {
            return null;
        }

        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $best = null;
        $start = $intervals[0][0];
        $end = $intervals[0][1];

        foreach (\array_slice($intervals, 1) as [$nextStart, $nextEnd]) {
            if ($nextStart > $end) {
                $best = $this->longer($best, $start, $end, $context);
                $start = $nextStart;
                $end = $nextEnd;
                continue;
            }
            if ($nextEnd > $end) {
                $end = $nextEnd;
            }
        }

        return $this->longer($best, $start, $end, $context);
    }

    /**
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null $best
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function longer(?array $best, \DateTimeImmutable $start, \DateTimeImmutable $end, BoardContext $context): ?array
    {
        if ($start >= $context->dayEnd || $end <= $context->dayStart) {
            return $best;
        }

        if ($best === null) {
            return [$start, $end];
        }

        $bestLength = $best[1]->getTimestamp() - $best[0]->getTimestamp();

        return ($end->getTimestamp() - $start->getTimestamp()) > $bestLength ? [$start, $end] : $best;
    }

    /** Schedule-driven: it changes when an assignment changes, and that publishes its own signal. */
    public function transitions(BoardContext $context): array
    {
        return [];
    }
}
