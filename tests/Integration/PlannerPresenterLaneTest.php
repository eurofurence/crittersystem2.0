<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Shift;
use App\Enum\ShiftState;
use App\Service\Shift\LaneLayout;
use App\Service\Shift\PlannerPresenter;
use PHPUnit\Framework\TestCase;

/**
 * Lane assignment for the Standard Planner: shifts that run at the same time must sit side by side
 * in a day column rather than on top of each other, which is what made parallel shifts look deleted.
 *
 * Pure geometry, so this runs without a kernel or a database.
 */
final class PlannerPresenterLaneTest extends TestCase
{
    private const DAY = '2026-07-17';

    private function shift(string $start, string $end, int $id = 0): Shift
    {
        $tz = new \DateTimeZone('UTC');
        $shift = (new Shift())
            ->setTitle('S'.$id)
            ->setStartsAt(new \DateTimeImmutable(self::DAY.' '.$start, $tz))
            ->setEndsAt(new \DateTimeImmutable($end < $start ? '2026-07-18 '.$end : self::DAY.' '.$end, $tz))
            ->setState(ShiftState::DRAFT)
            ->setDepartment(new Department('Stage', 'stage'));

        // Lane order tie-breaks on the id, which is normally database-assigned.
        $ref = new \ReflectionProperty(Shift::class, 'id');
        $ref->setValue($shift, $id);

        return $shift;
    }

    /**
     * @param Shift[] $shifts
     *
     * @return array{days: array, blocks: array, overflows: array, rasterMinutes: int}
     */
    private function grid(array $shifts): array
    {
        $tz = new \DateTimeZone('UTC');

        return (new PlannerPresenter(new LaneLayout()))->buildGrid(
            new \DateTimeImmutable(self::DAY.' 00:00', $tz),
            new \DateTimeImmutable('2026-07-18 00:00', $tz),
            $shifts,
            $tz,
        );
    }

    /** @return array<string, array{lane: int, lanes: int, left: float, width: float}> keyed by title */
    private function lanesByTitle(array $grid): array
    {
        $out = [];
        foreach ($grid['blocks'] as $block) {
            $out[$block['shift']->getTitle()] = [
                'lane' => $block['lane'],
                'lanes' => $block['lanes'],
                'left' => $block['left'],
                'width' => $block['width'],
            ];
        }

        return $out;
    }

    public function testALoneShiftKeepsTheFullWidth(): void
    {
        $lanes = $this->lanesByTitle($this->grid([$this->shift('10:00', '12:00', 1)]));

        self::assertSame(['lane' => 0, 'lanes' => 1, 'left' => 0.0, 'width' => 100.0], $lanes['S1']);
    }

    public function testTwoShiftsAtIdenticalTimesSplitTheColumn(): void
    {
        $lanes = $this->lanesByTitle($this->grid([
            $this->shift('22:00', '23:30', 1),
            $this->shift('22:00', '23:30', 2),
        ]));

        self::assertSame(2, $lanes['S1']['lanes']);
        self::assertSame(2, $lanes['S2']['lanes']);
        self::assertNotSame($lanes['S1']['lane'], $lanes['S2']['lane']);
        self::assertSame(50.0, $lanes['S1']['width']);
        self::assertSame([0.0, 50.0], [$lanes['S1']['left'], $lanes['S2']['left']]);
    }

    public function testPartiallyOverlappingShiftsSplitTheColumn(): void
    {
        $lanes = $this->lanesByTitle($this->grid([
            $this->shift('10:00', '12:00', 1),
            $this->shift('11:00', '13:00', 2),
        ]));

        self::assertSame(2, $lanes['S1']['lanes']);
        self::assertSame(2, $lanes['S2']['lanes']);
    }

    /**
     * The boundary everyone gets wrong: back-to-back shifts do not overlap, so neither may be
     * narrowed. Getting this wrong would halve the width of every consecutive shift in a day.
     */
    public function testTouchingShiftsAreNotOverlapping(): void
    {
        $lanes = $this->lanesByTitle($this->grid([
            $this->shift('10:00', '12:00', 1),
            $this->shift('12:00', '14:00', 2),
        ]));

        self::assertSame(1, $lanes['S1']['lanes']);
        self::assertSame(1, $lanes['S2']['lanes']);
        self::assertSame(100.0, $lanes['S1']['width']);
        self::assertSame(0.0, $lanes['S2']['left']);
    }

    /**
     * A overlaps B and B overlaps C, but A and C do not. They form one cluster two lanes wide, and
     * C reuses A's lane rather than forcing a third.
     */
    public function testAChainedClusterReusesFreedLanes(): void
    {
        $lanes = $this->lanesByTitle($this->grid([
            $this->shift('10:00', '12:00', 1),
            $this->shift('11:00', '14:00', 2),
            $this->shift('13:00', '15:00', 3),
        ]));

        self::assertSame(2, $lanes['S1']['lanes']);
        self::assertSame(2, $lanes['S3']['lanes']);
        self::assertSame($lanes['S1']['lane'], $lanes['S3']['lane'], 'C should reuse the lane A vacated');
        self::assertNotSame($lanes['S1']['lane'], $lanes['S2']['lane']);
    }

    /**
     * Clustering still decides which lane a shift lands in - an unrelated afternoon shift takes the
     * first free lane rather than being pushed past the morning pair - even though the lane width
     * itself is now the day's.
     */
    public function testAnUnrelatedLaterShiftTakesTheFirstLane(): void
    {
        $lanes = $this->lanesByTitle($this->grid([
            $this->shift('08:00', '10:00', 1),
            $this->shift('09:00', '11:00', 2),
            $this->shift('15:00', '16:00', 3),
        ]));

        self::assertSame(0, $lanes['S3']['lane']);
        self::assertSame(0.0, $lanes['S3']['left']);
        self::assertSame(2, $lanes['S3']['lanes'], 'lane width comes from the busiest moment of the day');
    }

    /**
     * However many shifts run in parallel, every one of them renders: the column is widened rather
     * than capped. Hiding the sixth shift behind an "expand" link is what made a busy day look
     * half-planned.
     */
    public function testEveryParallelShiftRendersHoweverManyThereAre(): void
    {
        $shifts = [];
        for ($i = 1; $i <= 8; ++$i) {
            $shifts[] = $this->shift('22:00', '23:30', $i);
        }
        $grid = $this->grid($shifts);

        self::assertCount(8, $grid['blocks']);
        self::assertSame(8, $grid['days'][0]['lanes'], 'the day carries its lane count so the column can be sized');

        foreach ($grid['blocks'] as $block) {
            self::assertSame(8, $block['lanes']);
        }
        self::assertSame(range(0, 7), array_column($grid['blocks'], 'lane'));
    }

    /**
     * Lanes are uniform across the whole day, not per cluster: a quiet morning in a busy day keeps
     * the same lane width as the afternoon, so every block sits on one vertical grid.
     */
    public function testLanesAreUniformAcrossTheWholeDay(): void
    {
        $shifts = [$this->shift('08:00', '09:00', 1)];
        for ($i = 2; $i <= 5; ++$i) {
            $shifts[] = $this->shift('20:00', '21:00', $i);
        }

        $grid = $this->grid($shifts);
        $lanes = $this->lanesByTitle($grid);

        self::assertSame(4, $grid['days'][0]['lanes']);
        self::assertSame(25.0, $lanes['S1']['width'], 'the lone morning shift keeps one lane, not the whole column');
        self::assertSame(25.0, $lanes['S2']['width']);
        self::assertSame(0.0, $lanes['S1']['left']);
    }

    /** A day nobody is running anything on still has one lane, so its column has a width. */
    public function testAnEmptyDayHasASingleLane(): void
    {
        self::assertSame(1, $this->grid([])['days'][0]['lanes']);
    }

    /**
     * Lanes are derived from the part of the shift actually drawn in the column. An overnight shift
     * is clamped at midnight, so it must not reserve width against a shift starting after it ends
     * on screen.
     */
    public function testAnOvernightShiftReservesOnlyItsVisibleSpan(): void
    {
        $grid = $this->grid([
            $this->shift('23:00', '02:00', 1), // runs into the next day
            $this->shift('08:00', '09:00', 2),
        ]);
        $lanes = $this->lanesByTitle($grid);

        self::assertSame(1, $lanes['S1']['lanes']);
        self::assertSame(1, $lanes['S2']['lanes'], 'a morning shift is unaffected by the overnight one');
    }

    /** Repeated renders of the same data must not shuffle the blocks between lanes. */
    public function testLaneAssignmentIsStableAcrossRenders(): void
    {
        $shifts = [
            $this->shift('22:00', '23:00', 7),
            $this->shift('22:00', '23:30', 3),
            $this->shift('22:30', '23:30', 5),
        ];

        $first = $this->lanesByTitle($this->grid($shifts));
        $second = $this->lanesByTitle($this->grid(array_reverse($shifts)));

        self::assertSame($first, $second, 'lane assignment must not depend on input order');
    }
}
