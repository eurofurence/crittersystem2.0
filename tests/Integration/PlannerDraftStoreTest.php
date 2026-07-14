<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Enum\ShiftState;
use App\Service\Shift\PlannerDraftStore;
use App\Tests\DatabaseTestCase;

/**
 * Standard Planner draft persistence and paint consolidation: painted intervals merge into
 * consolidated draft shifts, drafts are created invisible, and the 30-minute minimum is enforced.
 */
final class PlannerDraftStoreTest extends DatabaseTestCase
{
    private function store(): PlannerDraftStore
    {
        return static::getContainer()->get(PlannerDraftStore::class);
    }

    private function dept(): Department
    {
        $d = new Department('Dept '.bin2hex(random_bytes(3)), 'd-'.bin2hex(random_bytes(3)));
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    /** @param array<int, array{0:string,1:string}> $pairs */
    private function intervals(array $pairs): array
    {
        return array_map(
            static fn ($p) => [new \DateTimeImmutable($p[0]), new \DateTimeImmutable($p[1])],
            $pairs,
        );
    }

    public function testConsolidateMergesOverlappingAndTouchingIntervals(): void
    {
        $intervals = $this->intervals([
            ['2026-06-01 10:00', '2026-06-01 11:00'],
            ['2026-06-01 11:00', '2026-06-01 12:00'], // touches previous -> merge
            ['2026-06-01 11:30', '2026-06-01 12:30'], // overlaps -> merge
            ['2026-06-01 14:00', '2026-06-01 15:00'], // separate
        ]);

        $merged = $this->store()->consolidateIntervals($intervals);

        self::assertCount(2, $merged);
        self::assertEquals(new \DateTimeImmutable('2026-06-01 10:00'), $merged[0][0]);
        self::assertEquals(new \DateTimeImmutable('2026-06-01 12:30'), $merged[0][1]);
        self::assertEquals(new \DateTimeImmutable('2026-06-01 14:00'), $merged[1][0]);
    }

    public function testCreateConsolidatedPersistsOneDraftPerMergedSpan(): void
    {
        $dept = $this->dept();
        $shifts = $this->store()->createConsolidated($dept, $this->intervals([
            ['2026-06-01 10:00', '2026-06-01 11:00'],
            ['2026-06-01 11:00', '2026-06-01 12:00'],
            ['2026-06-01 14:00', '2026-06-01 15:00'],
        ]));

        self::assertCount(2, $shifts);
        foreach ($shifts as $shift) {
            self::assertSame(ShiftState::DRAFT, $shift->getState(), 'painted shifts are drafts');
            self::assertNotNull($shift->getId());
        }
        // First merged span is 10:00–12:00 = 2h.
        self::assertSame(2.0, $shifts[0]->getDurationHours());
    }

    public function testMinimumDurationIsEnforced(): void
    {
        $dept = $this->dept();
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->createDraft(
            $dept,
            new \DateTimeImmutable('2026-06-01 10:00'),
            new \DateTimeImmutable('2026-06-01 10:15'), // 15 min < 30 min minimum
        );
    }

    public function testMinutePrecisionStartIsAllowed(): void
    {
        $dept = $this->dept();
        $shift = $this->store()->createDraft(
            $dept,
            new \DateTimeImmutable('2026-06-01 13:12'),
            new \DateTimeImmutable('2026-06-01 14:42'),
        );
        self::assertSame('13:12', $shift->getStartsAt()->format('H:i'));
        self::assertSame(1.5, $shift->getDurationHours());
    }

    public function testRescheduleMovesTheShift(): void
    {
        $dept = $this->dept();
        $shift = $this->store()->createDraft(
            $dept,
            new \DateTimeImmutable('2026-06-01 10:00'),
            new \DateTimeImmutable('2026-06-01 12:00'),
        );
        $this->store()->reschedule(
            $shift,
            new \DateTimeImmutable('2026-06-01 13:00'),
            new \DateTimeImmutable('2026-06-01 16:00'),
        );
        self::assertSame('13:00', $shift->getStartsAt()->format('H:i'));
        self::assertSame(3.0, $shift->getDurationHours());
    }
}
