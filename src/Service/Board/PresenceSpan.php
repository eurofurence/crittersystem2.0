<?php

namespace App\Service\Board;

/**
 * One stretch of time a volunteer was present, from either source the application records:
 * a duty session in the department, or a check-in against one of its shifts.
 *
 * There is no break entity anywhere in the application, so a gap between two spans is what a break
 * is here. Merging adjacent spans is therefore load-bearing rather than tidiness: it is what decides
 * whether somebody has been working continuously or has stepped away in between.
 */
final class PresenceSpan
{
    public function __construct(
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $endedAt,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    public function coversInstant(\DateTimeImmutable $at): bool
    {
        return $this->startedAt <= $at && ($this->endedAt === null || $this->endedAt > $at);
    }

    public function overlaps(\DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return $this->startedAt < $to && ($this->endedAt === null || $this->endedAt > $from);
    }

    /** Elapsed time in minutes, measured to $now while the span is still open. */
    public function minutes(\DateTimeImmutable $now): int
    {
        $end = $this->endedAt ?? $now;
        if ($end <= $this->startedAt) {
            return 0;
        }

        return intdiv($end->getTimestamp() - $this->startedAt->getTimestamp(), 60);
    }

    /**
     * Merge overlapping or touching spans into the longest continuous stretches. Input order does
     * not matter; the result is ordered oldest first.
     *
     * @param list<self> $spans
     *
     * @return list<self>
     */
    public static function merge(array $spans): array
    {
        if ($spans === []) {
            return [];
        }

        usort($spans, static fn (self $a, self $b): int => $a->startedAt <=> $b->startedAt);

        $merged = [];
        $current = $spans[0];
        foreach (\array_slice($spans, 1) as $span) {
            // An open span swallows everything after it: it has not finished, so nothing that starts
            // later can be separated from it by a gap.
            if ($current->endedAt === null) {
                continue;
            }

            if ($span->startedAt > $current->endedAt) {
                $merged[] = $current;
                $current = $span;
                continue;
            }

            $current = new self(
                $current->startedAt,
                $span->endedAt === null || $span->endedAt > $current->endedAt ? $span->endedAt : $current->endedAt,
            );
        }
        $merged[] = $current;

        return $merged;
    }
}
