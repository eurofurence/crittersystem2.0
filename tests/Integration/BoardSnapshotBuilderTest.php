<?php

namespace App\Tests\Integration;

use App\Entity\DutyRecord;
use App\Entity\Shift;
use App\Entity\User;
use App\Service\Board\BoardSnapshotBuilder;
use App\Service\Board\BoardVolunteer;
use App\Service\Board\ShiftStatus;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The board's read model, against hand-computed fixtures.
 *
 * Everything the board shows is derived here, so these assertions are the definition of what each
 * number means: which people count as present, what "needed" sums over, and when a rule fires. A
 * panel disagreeing with a tile would be this file's failure first.
 */
final class BoardSnapshotBuilderTest extends DatabaseTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );
    }

    private function builder(): BoardSnapshotBuilder
    {
        return static::getContainer()->get(BoardSnapshotBuilder::class);
    }

    private function day(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today '.$time, new \DateTimeZone('UTC'));
    }

    private function shiftAt(string $title, string $from, string $to, int $needed): Shift
    {
        $shift = $this->scenario->shift($title, 'today '.$from, '+1 minute', $needed);
        $shift->setStartsAt($this->at($from))->setEndsAt($this->at($to));
        $this->em->flush();

        return $shift;
    }

    private function onDuty(User $user, string $from, ?string $to = null): void
    {
        $record = new DutyRecord($user, $this->scenario->department);
        $reflection = new \ReflectionProperty(DutyRecord::class, 'startedAt');
        $reflection->setValue($record, $this->at($from));
        if ($to !== null) {
            $record->setEndedAt($this->at($to));
        }
        $this->em->persist($record);
        $this->em->flush();
    }

    public function testCountsWhoIsPlannedAgainstWhoIsActuallyPresent(): void
    {
        $morning = $this->shiftAt('Morning', '08:00', '12:00', 2);
        $afternoon = $this->shiftAt('Afternoon', '13:00', '17:00', 3);

        $alice = $this->scenario->user();
        $bob = $this->scenario->user();
        $carol = $this->scenario->user();

        $this->scenario->signUp($alice, $morning)->checkIn($this->at('08:00'));
        $this->scenario->signUp($bob, $morning);
        $this->scenario->signUp($carol, $afternoon);
        $this->em->flush();

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        self::assertSame(3, $snapshot->plannedCount, 'everyone the day involves');
        self::assertSame(1, $snapshot->activeCount, 'only Alice is checked in');
        self::assertSame(2, $snapshot->totalShiftCount());
        self::assertSame(1, $snapshot->activeShiftCount);
        self::assertNotNull($snapshot->nextShift);
        self::assertSame('Afternoon', $snapshot->nextShift->shift->getTitle());
    }

    /** An open duty session in the department counts as being present, with no check-in involved. */
    public function testADutySessionAloneMakesSomebodyActive(): void
    {
        $this->shiftAt('Morning', '08:00', '12:00', 1);
        $dana = $this->scenario->user();
        $this->onDuty($dana, '09:00');

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        self::assertSame(1, $snapshot->activeCount);
        self::assertSame(1, $snapshot->plannedCount);
        self::assertTrue($snapshot->activeStaff[0]->isPresent());
        self::assertEquals($this->at('09:00'), $snapshot->activeStaff[0]->presentSince);
    }

    public function testNeededIsTheSumOfEveryRequirementAcrossOverlappingShifts(): void
    {
        $this->shiftAt('Morning', '08:00', '12:00', 2);
        $this->shiftAt('Roaming', '09:00', '11:00', 3);

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        $now = $snapshot->forecast->buckets[0];
        self::assertTrue($now->isNow);
        self::assertSame(5, $now->needed, 'both shifts overlap the 10:00 hour');
        self::assertSame(0, $now->planned);
        self::assertSame(-5, $now->difference());
        self::assertSame(3, $now->heat(), 'nothing covered is the worst band');
    }

    /** Somebody holding two overlapping shifts is one person, not two. */
    public function testForecastCountsDistinctVolunteers(): void
    {
        $one = $this->shiftAt('Morning', '08:00', '12:00', 1);
        $two = $this->shiftAt('Roaming', '09:00', '11:00', 1);

        $eve = $this->scenario->user();
        $this->scenario->signUp($eve, $one);
        $this->scenario->signUp($eve, $two);

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        self::assertSame(2, $snapshot->forecast->buckets[0]->needed);
        self::assertSame(1, $snapshot->forecast->buckets[0]->planned);
    }

    public function testUnderstaffedAndImminentIsCriticalWhenNobodyIsAssigned(): void
    {
        $this->shiftAt('Afternoon', '13:00', '17:00', 3);

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('12:50'));

        $types = array_map(static fn ($item): string => $item->type, $snapshot->attention);
        self::assertContains('understaffed_imminent', $types);

        $item = $snapshot->attention[0];
        self::assertSame('understaffed_imminent', $item->type);
        self::assertSame('critical', $item->severity->value);
        self::assertSame(3, $snapshot->openPositions);
    }

    public function testTheImminentWindowDoesNotFireTooEarly(): void
    {
        $this->shiftAt('Afternoon', '13:00', '17:00', 3);

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('12:30'));

        self::assertSame([], $snapshot->attention);
        self::assertSame(0, $snapshot->openPositions, 'a gap hours away belongs in the forecast');
    }

    public function testAStaffedButEmptyShiftIsReportedAsUnattended(): void
    {
        $morning = $this->shiftAt('Morning', '08:00', '12:00', 1);
        $frank = $this->scenario->user();
        $this->scenario->signUp($frank, $morning);

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('08:30'));

        $types = array_map(static fn ($item): string => $item->type, $snapshot->attention);
        self::assertContains('unattended_position', $types);
    }

    public function testCheckingInClearsTheUnattendedItem(): void
    {
        $morning = $this->shiftAt('Morning', '08:00', '12:00', 1);
        $frank = $this->scenario->user();
        $this->scenario->signUp($frank, $morning)->checkIn($this->at('08:05'));
        $this->em->flush();

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('08:30'));

        $types = array_map(static fn ($item): string => $item->type, $snapshot->attention);
        self::assertNotContains('unattended_position', $types);
    }

    public function testContinuousPresenceFiresOnlyAfterTheLimit(): void
    {
        $this->shiftAt('Long', '02:00', '20:00', 1);
        $grace = $this->scenario->user();
        $this->onDuty($grace, '03:00');

        $before = $this->builder()->build($this->scenario->department, $this->day(), $this->at('08:30'));
        $after = $this->builder()->build($this->scenario->department, $this->day(), $this->at('09:30'));

        self::assertNotContains('continuous_presence', array_map(static fn ($i): string => $i->type, $before->attention));
        self::assertContains('continuous_presence', array_map(static fn ($i): string => $i->type, $after->attention));
    }

    /** A gap between two stretches is what a break is here, so it has to reset the clock. */
    public function testAGapInPresenceResetsTheContinuousClock(): void
    {
        $this->shiftAt('Long', '02:00', '20:00', 1);
        $grace = $this->scenario->user();
        $this->onDuty($grace, '02:00', '07:00');
        $this->onDuty($grace, '07:30');

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('12:00'));

        self::assertNotContains('continuous_presence', array_map(static fn ($i): string => $i->type, $snapshot->attention));
    }

    public function testShiftStatusesReflectTheClockAndTheStaffing(): void
    {
        $done = $this->shiftAt('Done', '06:00', '07:00', 1);
        $running = $this->shiftAt('Running', '09:00', '11:00', 1);
        $short = $this->shiftAt('Short', '09:30', '11:30', 2);
        $later = $this->shiftAt('Later', '18:00', '20:00', 1);

        $henry = $this->scenario->user();
        $this->scenario->signUp($henry, $done);
        $this->scenario->signUp($henry, $running);
        $this->scenario->signUp($henry, $short);
        $this->scenario->signUp($henry, $later);

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        $byTitle = [];
        foreach ($snapshot->shiftRows as $row) {
            $byTitle[$row->shift->getTitle()] = $row->status;
        }

        self::assertSame(ShiftStatus::Done, $byTitle['Done']);
        self::assertSame(ShiftStatus::Active, $byTitle['Running']);
        self::assertSame(ShiftStatus::Understaffed, $byTitle['Short']);
        self::assertSame(ShiftStatus::Upcoming, $byTitle['Later']);
    }

    public function testStaffAreRankedMostLoadedFirst(): void
    {
        $long = $this->shiftAt('Long', '08:00', '18:00', 2);
        $brief = $this->shiftAt('Brief', '08:00', '09:00', 2);

        $heavy = $this->scenario->user();
        $light = $this->scenario->user();
        $this->scenario->signUp($heavy, $long);
        $this->scenario->signUp($light, $brief);

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        self::assertCount(2, $snapshot->staff);
        self::assertSame($heavy->getId(), $snapshot->staff[0]->user->getId());
        self::assertGreaterThan($snapshot->staff[1]->totalHours, $snapshot->staff[0]->totalHours);
    }

    public function testVolunteerStatusDistinguishesPresentFromArriving(): void
    {
        $later = $this->shiftAt('Later', '18:00', '20:00', 1);
        $arriving = $this->scenario->user();
        $this->scenario->signUp($arriving, $later);

        $present = $this->scenario->user();
        $this->onDuty($present, '09:00');

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        $byUser = [];
        foreach ($snapshot->staff as $volunteer) {
            $byUser[$volunteer->user->getId()] = $volunteer->status;
        }

        self::assertSame(BoardVolunteer::STATUS_ARRIVING, $byUser[$arriving->getId()]);
        self::assertSame(BoardVolunteer::STATUS_ON_DUTY, $byUser[$present->getId()]);
    }

    public function testComingNextAndRecentlyOffUseTheirConfiguredWindows(): void
    {
        $soon = $this->shiftAt('Soon', '10:30', '12:00', 1);
        $far = $this->shiftAt('Far', '14:00', '16:00', 1);

        $arriving = $this->scenario->user();
        $this->scenario->signUp($arriving, $soon);
        $notYet = $this->scenario->user();
        $this->scenario->signUp($notYet, $far);

        $left = $this->scenario->user();
        $this->onDuty($left, '08:00', '09:40');

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        self::assertCount(1, $snapshot->comingNext);
        self::assertSame($arriving->getId(), $snapshot->comingNext[0]->user->getId());

        self::assertCount(1, $snapshot->recentlyOff);
        self::assertSame($left->getId(), $snapshot->recentlyOff[0]->user->getId());
    }

    /**
     * The board re-renders at the exact instant its content changes rather than on a timer, so this
     * has to be the earliest such moment - getting it wrong leaves a wall display stale.
     */
    public function testNextTransitionIsTheEarliestFutureBoundary(): void
    {
        $this->shiftAt('Afternoon', '13:00', '17:00', 1);

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:20'));

        // The next hour boundary at 11:00 beats the shift's warning window at 12:45.
        self::assertEquals($this->at('11:00'), $snapshot->nextTransitionAt);

        $later = $this->builder()->build($this->scenario->department, $this->day(), $this->at('12:30'));
        self::assertEquals($this->at('12:45'), $later->nextTransitionAt, 'the warning window opens first');
    }

    public function testWorkloadDistributionCountsEverybodyExactlyOnce(): void
    {
        $short = $this->shiftAt('Short', '08:00', '13:00', 3);
        $long = $this->shiftAt('Long', '08:00', '20:00', 3);

        $a = $this->scenario->user();
        $b = $this->scenario->user();
        $this->scenario->signUp($a, $short);
        $this->scenario->signUp($b, $long);

        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        self::assertSame(2, $snapshot->workload->total);
        self::assertSame(2, array_sum(array_map(static fn ($band): int => $band->count, $snapshot->workload->bands)));
    }

    public function testAnEmptyDayProducesAnEmptyBoardRatherThanAnError(): void
    {
        $snapshot = $this->builder()->build($this->scenario->department, $this->day(), $this->at('10:00'));

        self::assertTrue($snapshot->isEmpty());
        self::assertSame(0, $snapshot->activeCount);
        self::assertSame([], $snapshot->attention);
        self::assertNull($snapshot->nextShift);
        self::assertNotNull($snapshot->nextTransitionAt, 'the hour still rolls over');
    }
}
