<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Location;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftEntryState;
use App\Service\Shift\ScheduleTimelineService;
use App\Tests\DatabaseTestCase;

/**
 * The staff schedule: a column per person, a row per half hour, and a row of its own for a staff
 * shift nobody is on.
 */
final class ScheduleTimelineServiceTest extends DatabaseTestCase
{
    private ?Department $department = null;
    private ?VolunteerType $type = null;

    private function service(): ScheduleTimelineService
    {
        return static::getContainer()->get(ScheduleTimelineService::class);
    }

    private function department(): Department
    {
        if ($this->department === null) {
            $this->department = new Department('Logistics', 'logistics-'.bin2hex(random_bytes(2)));
            $this->em->persist($this->department);
        }

        return $this->department;
    }

    private function type(): VolunteerType
    {
        if ($this->type === null) {
            $this->type = new VolunteerType('Crew '.bin2hex(random_bytes(2)));
            $this->em->persist($this->type);
        }

        return $this->type;
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail(bin2hex(random_bytes(4)).'@e.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function shift(string $start, string $end, ?User $assignee = null, ShiftAudience $audience = ShiftAudience::DEPARTMENT_STAFF): Shift
    {
        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($this->department())
            ->setAudience($audience);
        $this->em->persist($shift);

        if ($assignee !== null) {
            $entry = new ShiftEntry($shift, $this->type(), $assignee);
            $entry->setState(ShiftEntryState::ASSIGNMENT);
            $this->em->persist($entry);
        }

        return $shift;
    }

    /** @return array{users: array, rows: array} */
    private function build(): array
    {
        $this->em->flush();

        return $this->service()->build(
            $this->department(),
            new \DateTimeImmutable('2026-06-01 00:00'),
            new \DateTimeImmutable('2026-06-04 00:00'),
            new \DateTimeZone('UTC'),
        );
    }

    /** @param array{users: array, rows: array} $data */
    private function timesOf(array $data): array
    {
        return array_map(static fn (array $row): string => $row['time'], $data['rows']);
    }

    public function testEveryoneAssignedBecomesAColumnInNameOrder(): void
    {
        $this->shift('2026-06-01 10:00', '2026-06-01 11:00', $this->user('Zoe'));
        $this->shift('2026-06-01 10:00', '2026-06-01 11:00', $this->user('Alice'));

        $data = $this->build();

        self::assertSame(['Alice', 'Zoe'], array_map(static fn (User $u): string => $u->getName(), $data['users']));
    }

    /**
     * A shift fills every half hour it runs for, so reading down a column answers "who is busy at
     * eleven" without arithmetic. Only the first slot carries the name, because repeating it down a
     * four-hour shift is unreadable.
     */
    public function testAShiftFillsEverySlotItCoversAndIsNamedOnceAtItsStart(): void
    {
        $worker = $this->user('Zoe');
        $this->shift('2026-06-01 10:00', '2026-06-01 12:00', $worker);

        $data = $this->build();
        $covered = array_values(array_filter(
            $data['rows'],
            static fn (array $row): bool => isset($row['cells'][$worker->getId()]),
        ));

        self::assertCount(4, $covered, 'two hours is four half-hour slots');
        self::assertSame(['10:00', '10:30', '11:00', '11:30'], array_column($covered, 'time'));
        self::assertSame(
            [true, false, false, false],
            array_map(static fn (array $row): bool => $row['cells'][$worker->getId()]['start'], $covered),
        );
    }

    /** The day is laid out from its first shift to its last, rounded out to whole hours. */
    public function testOnlyTheHoursWithShiftsAreLaidOut(): void
    {
        $this->shift('2026-06-01 10:15', '2026-06-01 11:45', $this->user('Zoe'));

        $times = $this->timesOf($this->build());

        self::assertSame(['10:00', '10:30', '11:00', '11:30'], $times);
    }

    /** A day nobody works is not drawn at all: it would be a screen of empty half hours. */
    public function testADayWithNothingOnItIsSkipped(): void
    {
        $worker = $this->user('Zoe');
        $this->shift('2026-06-01 10:00', '2026-06-01 11:00', $worker);
        $this->shift('2026-06-03 10:00', '2026-06-03 11:00', $worker);

        $days = array_values(array_unique(array_filter(array_column($this->build()['rows'], 'dayLabel'))));

        self::assertCount(2, $days);
    }

    /** A shift running past midnight opens the next day rather than being clipped out of it. */
    public function testAnOvernightShiftCoversSlotsOnBothDays(): void
    {
        $worker = $this->user('Zoe');
        $this->shift('2026-06-01 23:00', '2026-06-02 01:00', $worker);

        $data = $this->build();
        $covered = array_values(array_filter(
            $data['rows'],
            static fn (array $row): bool => isset($row['cells'][$worker->getId()]),
        ));

        self::assertSame(['23:00', '23:30', '00:00', '00:30'], array_column($covered, 'time'));
    }

    /** The thing the schedule exists to catch: a staff shift with nobody on it. */
    public function testAStaffShiftWithNobodyOnItGetsARedRow(): void
    {
        $this->shift('2026-06-01 10:00', '2026-06-01 12:00', $this->user('Zoe'));
        $this->shift('2026-06-01 14:00', '2026-06-01 16:00');

        $missing = array_values(array_filter(
            $this->build()['rows'],
            static fn (array $row): bool => $row['kind'] === 'missing',
        ));

        self::assertCount(1, $missing);
        self::assertSame('14:00', $missing[0]['missing']['from']);
        self::assertSame('16:00', $missing[0]['missing']['to']);
        self::assertSame('14:00', $missing[0]['time'], 'the row sits where the shift starts');
    }

    /** An empty volunteer shift is open for sign-up, not a hole in the roster. */
    public function testAnEmptyPublicShiftIsNotReportedAsMissingStaff(): void
    {
        $this->shift('2026-06-01 14:00', '2026-06-01 16:00', null, ShiftAudience::PUBLIC_VOLUNTEER);

        $missing = array_filter($this->build()['rows'], static fn (array $row): bool => $row['kind'] === 'missing');

        self::assertSame([], $missing);
    }

    /** Somebody is covering it, so it is not missing staff even if it wants more people. */
    public function testAStaffShiftWithSomebodyOnItIsNotReportedAsMissing(): void
    {
        $this->shift('2026-06-01 14:00', '2026-06-01 16:00', $this->user('Zoe'));

        $missing = array_filter($this->build()['rows'], static fn (array $row): bool => $row['kind'] === 'missing');

        self::assertSame([], $missing);
    }

    /**
     * A shift does not have to sit on the half-hour raster, so the cell carries the shift's own
     * times: one running 10:15 to 12:45 fills the 10:00 slot and would otherwise read as 10:00.
     */
    public function testTheCellCarriesTheShiftsOwnTimesRatherThanTheSlots(): void
    {
        $worker = $this->user('Zoe');
        $this->shift('2026-06-01 10:15', '2026-06-01 12:45', $worker);

        $rows = $this->build()['rows'];
        $cell = $rows[0]['cells'][$worker->getId()];

        self::assertSame('10:00', $rows[0]['time'], 'the row is the slot');
        self::assertTrue($cell['start']);
        self::assertSame('10:15', $cell['from'], 'the cell is the shift');
        self::assertSame('12:45', $cell['to']);
    }

    public function testLocationAndTaskNameReachTheCell(): void
    {
        $location = (new Location('Main Gate'))->setAlias('main-gate-'.bin2hex(random_bytes(2)));
        $this->em->persist($location);
        $worker = $this->user('Zoe');
        $this->shift('2026-06-01 10:00', '2026-06-01 11:00', $worker)->setLocation($location);

        $cell = $this->build()['rows'][0]['cells'][$worker->getId()];

        self::assertSame('Gate', $cell['title']);
        self::assertSame('Main Gate', $cell['location']);
    }
}
