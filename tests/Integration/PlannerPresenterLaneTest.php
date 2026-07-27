<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Shift;
use App\Enum\ShiftState;
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
    private function grid(array $shifts, array $expandedDays = []): array
    {
        $tz = new \DateTimeZone('UTC');

        return (new PlannerPresenter())->buildGrid(
            new \DateTimeImmutable(self::DAY.' 00:00', $tz),
            new \DateTimeImmutable('2026-07-18 00:00', $tz),
            $shifts,
            $tz,
            null,
            null,
            $expandedDays,
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
     * Two overlapping shifts in the morning must not narrow an unrelated afternoon shift: clusters
     * are independent.
     */
    public function testSeparateClustersDoNotAffectEachOther(): void
    {
        $lanes = $this->lanesByTitle($this->grid([
            $this->shift('08:00', '10:00', 1),
            $this->shift('09:00', '11:00', 2),
            $this->shift('15:00', '16:00', 3),
        ]));

        self::assertSame(2, $lanes['S1']['lanes']);
        self::assertSame(1, $lanes['S3']['lanes'], 'a later, unrelated shift keeps the full width');
        self::assertSame(100.0, $lanes['S3']['width']);
    }

    public function testBeyondTheCapTheDayReportsWhatItIsHiding(): void
    {
        $shifts = [];
        for ($i = 1; $i <= 6; ++$i) {
            $shifts[] = $this->shift('22:00', '23:30', $i);
        }
        $grid = $this->grid($shifts);

        self::assertCount(PlannerPresenter::MAX_LANES, $grid['blocks'], 'only the capped lanes render');
        self::assertSame(2, $grid['days'][0]['hidden'], 'the day carries the hidden count for its header link');

        foreach ($grid['blocks'] as $block) {
            self::assertSame(PlannerPresenter::MAX_LANES, $block['lanes']);
        }
    }

    /** Hidden counts are summed per day, not per cluster: the header shows one number. */
    public function testHiddenCountsFromSeveralClustersAreSummedForTheDay(): void
    {
        $shifts = [];
        for ($i = 1; $i <= 6; ++$i) {
            $shifts[] = $this->shift('08:00', '09:00', $i);
        }
        for ($i = 7; $i <= 11; ++$i) {
            $shifts[] = $this->shift('20:00', '21:00', $i);
        }

        $grid = $this->grid($shifts);

        self::assertSame(3, $grid['days'][0]['hidden'], '2 hidden in the morning cluster plus 1 in the evening');
    }

    public function testADayWithinTheCapHidesNothing(): void
    {
        $grid = $this->grid([
            $this->shift('22:00', '23:30', 1),
            $this->shift('22:00', '23:30', 2),
        ]);

        self::assertSame(0, $grid['days'][0]['hidden']);
        self::assertFalse($grid['days'][0]['expanded']);
    }

    public function testAnExpandedDayIgnoresTheCap(): void
    {
        $shifts = [];
        for ($i = 1; $i <= 6; ++$i) {
            $shifts[] = $this->shift('22:00', '23:30', $i);
        }
        $grid = $this->grid($shifts, [self::DAY]);

        self::assertCount(6, $grid['blocks'], 'every parallel shift renders when the day is expanded');
        self::assertSame(0, $grid['days'][0]['hidden']);
        self::assertTrue($grid['days'][0]['expanded']);
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
