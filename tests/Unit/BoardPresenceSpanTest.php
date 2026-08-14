<?php

namespace App\Tests\Unit;

use App\Service\Board\PresenceSpan;
use PHPUnit\Framework\TestCase;

/**
 * Merging presence is what makes "how long without a break" answerable.
 *
 * The application records no breaks, so a gap between spans is the only thing that can stand in for
 * one. Merge too eagerly and a genuine break disappears; merge too little and a handover between two
 * shifts reads as a break the volunteer never took, and the overwork rule silently stops firing.
 */
final class BoardPresenceSpanTest extends TestCase
{
    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-19 '.$time, new \DateTimeZone('UTC'));
    }

    public function testTouchingSpansBecomeOneContinuousStretch(): void
    {
        $merged = PresenceSpan::merge([
            new PresenceSpan($this->at('08:00'), $this->at('12:00')),
            new PresenceSpan($this->at('12:00'), $this->at('16:00')),
        ]);

        self::assertCount(1, $merged);
        self::assertEquals($this->at('08:00'), $merged[0]->startedAt);
        self::assertEquals($this->at('16:00'), $merged[0]->endedAt);
        self::assertSame(480, $merged[0]->minutes($this->at('23:00')));
    }

    public function testOverlappingSpansMergeAndKeepTheLatestEnd(): void
    {
        $merged = PresenceSpan::merge([
            new PresenceSpan($this->at('08:00'), $this->at('14:00')),
            new PresenceSpan($this->at('10:00'), $this->at('12:00')),
        ]);

        self::assertCount(1, $merged);
        self::assertEquals($this->at('14:00'), $merged[0]->endedAt);
    }

    public function testARealGapSeparatesTheStretches(): void
    {
        $merged = PresenceSpan::merge([
            new PresenceSpan($this->at('08:00'), $this->at('12:00')),
            new PresenceSpan($this->at('12:30'), $this->at('16:00')),
        ]);

        self::assertCount(2, $merged);
        self::assertSame(240, $merged[0]->minutes($this->at('23:00')));
        self::assertSame(210, $merged[1]->minutes($this->at('23:00')));
    }

    public function testInputOrderDoesNotMatter(): void
    {
        $merged = PresenceSpan::merge([
            new PresenceSpan($this->at('12:30'), $this->at('16:00')),
            new PresenceSpan($this->at('08:00'), $this->at('12:00')),
        ]);

        self::assertCount(2, $merged);
        self::assertEquals($this->at('08:00'), $merged[0]->startedAt);
    }

    /** An open span has not finished, so nothing later can be separated from it by a gap. */
    public function testAnOpenSpanAbsorbsEverythingAfterIt(): void
    {
        $merged = PresenceSpan::merge([
            new PresenceSpan($this->at('08:00'), null),
            new PresenceSpan($this->at('12:30'), $this->at('16:00')),
        ]);

        self::assertCount(1, $merged);
        self::assertTrue($merged[0]->isOpen());
    }

    public function testAnOpenSpanIsMeasuredToNow(): void
    {
        $span = new PresenceSpan($this->at('08:00'), null);

        self::assertSame(165, $span->minutes($this->at('10:45')));
        self::assertTrue($span->coversInstant($this->at('10:45')));
        self::assertFalse($span->coversInstant($this->at('07:00')));
    }

    public function testAClosedSpanDoesNotCoverItsOwnEnd(): void
    {
        $span = new PresenceSpan($this->at('08:00'), $this->at('12:00'));

        self::assertTrue($span->coversInstant($this->at('11:59')));
        self::assertFalse($span->coversInstant($this->at('12:00')));
    }
}
