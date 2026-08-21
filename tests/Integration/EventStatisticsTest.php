<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\DutyRecord;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Entity\Worklog;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Service\EventConfigStore;
use App\Service\Statistics\EventStatisticsService;
use App\Tests\DatabaseTestCase;

/**
 * Event-wide closing totals.
 *
 * These protect the figures that get read out on stage: what the event window includes, that time a
 * person spent on two things at once is never counted twice, and that the wall-clock and credited
 * columns stay distinct. A wrong number here is not a broken page, it is a false claim made in
 * public, and none of it is visible in a rendered template.
 */
final class EventStatisticsTest extends DatabaseTestCase
{
    private const WINDOW_FROM = '2026-06-01 00:00';
    private const WINDOW_TO = '2026-06-10 00:00';

    private function service(): EventStatisticsService
    {
        return static::getContainer()->get(EventStatisticsService::class);
    }

    private function setWindow(string $from = self::WINDOW_FROM, string $to = self::WINDOW_TO): void
    {
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_BUILDUP_START, (new \DateTimeImmutable($from))->format(\DATE_ATOM));
        $config->set(EventConfigStore::KEY_TEARDOWN_END, (new \DateTimeImmutable($to))->format(\DATE_ATOM));
    }

    private function dept(): Department
    {
        $suffix = bin2hex(random_bytes(3));
        $d = new Department('Dept '.$suffix, 'dept-'.$suffix);
        $this->em->persist($d);

        return $d;
    }

    private function user(bool $staff = false): User
    {
        $u = new User();
        $u->setName('u'.bin2hex(random_bytes(3)))->setEmail(bin2hex(random_bytes(4)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($u);

        if ($staff) {
            $suffix = bin2hex(random_bytes(3));
            $group = new Group('Staff '.$suffix, 'staff-'.$suffix, 'ROLE_STAFF');
            $this->em->persist($group);
            $u->addGroup($group);
        }

        return $u;
    }

    private function type(): VolunteerType
    {
        $t = new VolunteerType('T'.bin2hex(random_bytes(3)));
        $this->em->persist($t);

        return $t;
    }

    private function shift(
        string $start,
        string $end,
        ?Department $dept = null,
        ShiftAudience $audience = ShiftAudience::PUBLIC_VOLUNTEER,
        ShiftState $state = ShiftState::PUBLISHED,
    ): Shift {
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($dept ?? $this->dept())
            ->setAudience($audience)
            ->setState($state);
        $this->em->persist($shift);

        return $shift;
    }

    private function assign(Shift $shift, User $user, bool $noshow = false): ShiftEntry
    {
        $entry = new ShiftEntry($shift, $this->type(), $user);
        $entry->setNoshow($noshow);
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * DutyRecord stamps startedAt in its constructor and exposes no setter, because nothing in the
     * application backdates a duty. Reaching past that here is the only way to place duty time
     * inside a historical event window.
     */
    private function duty(User $user, string $start, string $end): void
    {
        $record = new DutyRecord($user, null);
        $property = new \ReflectionProperty(DutyRecord::class, 'startedAt');
        $property->setValue($record, new \DateTimeImmutable($start));
        $record->setEndedAt(new \DateTimeImmutable($end));
        $this->em->persist($record);
    }

    public function testShiftsOutsideTheEventWindowAreExcluded(): void
    {
        $this->setWindow();
        $user = $this->user();
        $this->assign($this->shift('2026-06-02 10:00', '2026-06-02 16:00'), $user);
        $this->assign($this->shift('2026-05-01 10:00', '2026-05-01 16:00'), $user);
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertSame(1, $stats->shiftsPublished);
        self::assertEqualsWithDelta(6.0, $stats->worked->raw, 0.001);
    }

    /** A shift that starts before the window and ends inside it belongs to the event, whole. */
    public function testShiftStraddlingTheWindowBoundaryIsCountedWhole(): void
    {
        $this->setWindow();
        $this->assign($this->shift('2026-05-31 22:00', '2026-06-01 04:00'), $this->user());
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertSame(1, $stats->shiftsPublished);
        self::assertEqualsWithDelta(6.0, $stats->shiftHoursScheduled, 0.001);
    }

    /** Two overlapping bookings are six hours of a person's life, not nine. */
    public function testOverlappingShiftsCountSharedTimeOnce(): void
    {
        $this->setWindow();
        $user = $this->user();
        $this->assign($this->shift('2026-06-02 10:00', '2026-06-02 16:00'), $user);
        $this->assign($this->shift('2026-06-02 13:00', '2026-06-02 16:00'), $user);
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertEqualsWithDelta(6.0, $stats->worked->raw, 0.001);
        self::assertSame(1, $stats->usersActive);
    }

    /** The same two shifts worked by two different people are twelve hours, not six. */
    public function testOverlapDeduplicationIsPerPerson(): void
    {
        $this->setWindow();
        $shift = $this->shift('2026-06-02 10:00', '2026-06-02 16:00');
        $this->assign($shift, $this->user());
        $this->assign($shift, $this->user());
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertEqualsWithDelta(12.0, $stats->worked->raw, 0.001);
        self::assertSame(2, $stats->usersActive);
    }

    public function testDraftShiftsAreReportedSeparatelyAndAddNoHours(): void
    {
        $this->setWindow();
        $draft = $this->shift('2026-06-02 10:00', '2026-06-02 16:00', null, ShiftAudience::PUBLIC_VOLUNTEER, ShiftState::DRAFT);
        $this->assign($draft, $this->user());
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertSame(0, $stats->shiftsPublished);
        self::assertSame(1, $stats->shiftsDraft);
        self::assertEqualsWithDelta(0.0, $stats->worked->raw, 0.001);
        self::assertSame(0, $stats->entriesTotal);
    }

    /** A shift still to come is planned; only a shift that has ended has been worked. */
    public function testFutureShiftsArePlannedButNotWorked(): void
    {
        $future = new \DateTimeImmutable('+3 days');
        $this->setWindow('-1 day', '+10 days');
        $this->assign(
            $this->shift($future->format('Y-m-d H:i'), $future->modify('+6 hours')->format('Y-m-d H:i')),
            $this->user(),
        );
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertEqualsWithDelta(6.0, $stats->planned->raw, 0.001);
        self::assertEqualsWithDelta(0.0, $stats->worked->raw, 0.001);
    }

    /** A no-show was not there: no wall-clock time, and the credited column takes the penalty. */
    public function testNoShowAddsNoWallClockTimeAndPenalisesCredited(): void
    {
        $this->setWindow();
        $this->assign($this->shift('2026-06-02 10:00', '2026-06-02 16:00'), $this->user(), noshow: true);
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertEqualsWithDelta(0.0, $stats->worked->raw, 0.001);
        self::assertEqualsWithDelta(-12.0, $stats->worked->credited, 0.001);
        self::assertSame(1, $stats->noshows);
    }

    /** A night shift is rewarded at double, and the wall-clock column must not follow it. */
    public function testNightShiftDoublesCreditedHoursOnly(): void
    {
        $this->setWindow();
        $this->assign($this->shift('2026-06-02 02:00', '2026-06-02 06:00'), $this->user());
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertEqualsWithDelta(4.0, $stats->worked->raw, 0.001);
        self::assertEqualsWithDelta(8.0, $stats->worked->credited, 0.001);
    }

    /** Being on duty during your own shift is one stretch of time, not two. */
    public function testDutyTimeDuringAShiftIsNotCountedTwice(): void
    {
        $this->setWindow();
        $user = $this->user();
        $this->assign($this->shift('2026-06-02 10:00', '2026-06-02 16:00'), $user);
        $this->duty($user, '2026-06-02 12:00', '2026-06-02 18:00');
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertEqualsWithDelta(8.0, $stats->worked->raw, 0.001);
        self::assertEqualsWithDelta(6.0, $stats->dutyHours, 0.001);
        self::assertEqualsWithDelta(6.0, $stats->worked->credited, 0.001);
    }

    /** Duty left running across the event is clipped to the window rather than inflating it. */
    public function testDutyTimeIsClippedToTheWindow(): void
    {
        $this->setWindow();
        $this->duty($this->user(), '2026-05-01 00:00', '2026-07-01 00:00');
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertEqualsWithDelta(9 * 24, $stats->dutyHours, 0.001);
    }

    public function testManualWorklogHoursCountInBothColumns(): void
    {
        $this->setWindow();
        $user = $this->user();
        $worklog = new Worklog($user);
        $worklog->setHours(5.0)->setWorkedAt(new \DateTimeImmutable('2026-06-03 12:00'));
        $this->em->persist($worklog);
        $this->assign($this->shift('2026-06-02 10:00', '2026-06-02 16:00'), $user);
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertEqualsWithDelta(11.0, $stats->worked->raw, 0.001);
        self::assertEqualsWithDelta(11.0, $stats->worked->credited, 0.001);
        self::assertEqualsWithDelta(5.0, $stats->worklogHours, 0.001);
    }

    /** Hours split by who the person is; a staff member on a public shift counts as staff here. */
    public function testHoursSplitByStaffRole(): void
    {
        $this->setWindow();
        $shift = $this->shift('2026-06-02 10:00', '2026-06-02 16:00');
        $this->assign($shift, $this->user(staff: true));
        $this->assign($shift, $this->user());
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertSame(1, $stats->usersActiveStaff);
        self::assertSame(1, $stats->usersActiveVolunteer);
        self::assertEqualsWithDelta(6.0, $stats->workedStaff->raw, 0.001);
        self::assertEqualsWithDelta(6.0, $stats->workedVolunteer->raw, 0.001);
        self::assertEqualsWithDelta(12.0, $stats->worked->raw, 0.001);
    }

    /** Shifts split by what they were offered as, which is a different question from who worked them. */
    public function testShiftsSplitByAudience(): void
    {
        $this->setWindow();
        $this->shift('2026-06-02 10:00', '2026-06-02 16:00', null, ShiftAudience::PUBLIC_VOLUNTEER);
        $this->shift('2026-06-03 10:00', '2026-06-03 16:00', null, ShiftAudience::ALL_STAFF);
        $this->shift('2026-06-04 10:00', '2026-06-04 16:00', null, ShiftAudience::INVITE_ONLY);
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertSame(1, $stats->shiftsVolunteerAudience);
        self::assertSame(2, $stats->shiftsStaffAudience);
        self::assertSame(1, $stats->shiftsByAudience[ShiftAudience::ALL_STAFF->value]);
        self::assertEqualsWithDelta(6.0, $stats->shiftHoursByAudience[ShiftAudience::INVITE_ONLY->value], 0.001);
    }

    public function testSlotsNeededAndFilled(): void
    {
        $this->setWindow();
        $shift = $this->shift('2026-06-02 10:00', '2026-06-02 16:00');
        $needed = new NeededVolunteerType($this->type(), 4);
        $needed->setShift($shift);
        $this->em->persist($needed);
        $this->assign($shift, $this->user());
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertSame(4, $stats->slotsNeeded);
        self::assertSame(1, $stats->slotsFilled);
        self::assertEqualsWithDelta(0.25, $stats->fillRate(), 0.001);
    }

    /** With no event dates configured the dashboard reports on everything rather than nothing. */
    public function testUnconfiguredEventDatesReportEverything(): void
    {
        $this->assign($this->shift('2020-01-01 10:00', '2020-01-01 16:00'), $this->user());
        $this->em->flush();

        $stats = $this->service()->compute();

        self::assertFalse($stats->window->isBounded());
        self::assertSame(1, $stats->shiftsPublished);
        self::assertEqualsWithDelta(6.0, $stats->worked->raw, 0.001);
    }
}
