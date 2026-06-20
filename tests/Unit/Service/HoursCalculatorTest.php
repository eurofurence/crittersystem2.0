<?php

namespace App\Tests\Unit\Service;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Repository\ShiftEntryRepository;
use App\Repository\WorklogRepository;
use App\Service\HoursCalculator;
use PHPUnit\Framework\TestCase;

final class HoursCalculatorTest extends TestCase
{
    private function calculator(): HoursCalculator
    {
        // entryHours()/overlapsNight() do not touch the repositories.
        return new HoursCalculator(
            $this->createStub(ShiftEntryRepository::class),
            $this->createStub(WorklogRepository::class),
        );
    }

    private function shift(string $start, string $end): Shift
    {
        return (new Shift())
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end));
    }

    private function entry(Shift $shift, bool $noshow = false): ShiftEntry
    {
        $entry = new ShiftEntry($shift, new VolunteerType('Heaven'), new User());
        $entry->setNoshow($noshow);

        return $entry;
    }

    public function testDayShiftCountsBaseHours(): void
    {
        $hours = $this->calculator()->entryHours($this->entry($this->shift('2026-09-01 10:00', '2026-09-01 14:00')));

        self::assertSame(4.0, $hours);
    }

    public function testOvernightShiftGetsNightMultiplier(): void
    {
        $hours = $this->calculator()->entryHours($this->entry($this->shift('2026-09-01 22:00', '2026-09-02 06:00')));

        self::assertSame(16.0, $hours); // 8h x2
    }

    public function testNoShowAppliesNegativePenalty(): void
    {
        $hours = $this->calculator()->entryHours($this->entry($this->shift('2026-09-01 10:00', '2026-09-01 14:00'), true));

        self::assertSame(-8.0, $hours); // 4h x-2
    }

    public function testOverlapsNightDetection(): void
    {
        $calc = $this->calculator();

        self::assertTrue($calc->overlapsNight($this->shift('2026-09-01 03:00', '2026-09-01 04:00')));
        self::assertFalse($calc->overlapsNight($this->shift('2026-09-01 10:00', '2026-09-01 14:00')));
        self::assertFalse($calc->overlapsNight($this->shift('2026-09-01 08:00', '2026-09-01 12:00')));
    }
}
