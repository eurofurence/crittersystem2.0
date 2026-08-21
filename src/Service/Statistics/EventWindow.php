<?php

namespace App\Service\Statistics;

use App\Service\EventConfigStore;

/**
 * The period the closing statistics cover.
 *
 * Buildup and teardown are part of the event as volunteers experience it, so the window runs from
 * buildup_start to teardown_end and falls back inwards to event_start/event_end when only those are
 * configured. An instance with no dates configured at all reports on everything it holds, and the
 * dashboard says so, because "total shifts" means something different in that case.
 */
final readonly class EventWindow
{
    private function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $to,
    ) {
    }

    public static function fromConfig(EventConfigStore $config): self
    {
        $from = $config->getDate(EventConfigStore::KEY_BUILDUP_START)
            ?? $config->getDate(EventConfigStore::KEY_EVENT_START);
        $to = $config->getDate(EventConfigStore::KEY_TEARDOWN_END)
            ?? $config->getDate(EventConfigStore::KEY_EVENT_END);

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self($from, $to);
    }

    public static function unbounded(): self
    {
        return new self(null, null);
    }

    /** False when the event dates are unset, meaning the figures cover the whole database. */
    public function isBounded(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /** Whether a period overlaps the window at all, so a shift straddling a boundary still counts. */
    public function overlaps(\DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        return ($this->to === null || $start < $this->to)
            && ($this->from === null || $end > $this->from);
    }

    public function days(): ?float
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }

        return ($this->to->getTimestamp() - $this->from->getTimestamp()) / 86400;
    }
}
