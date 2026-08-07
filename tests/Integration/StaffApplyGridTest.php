<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Service\Shift\StaffApplyGrid;
use App\Tests\DatabaseTestCase;

/**
 * The staff shift application grid: one day of staff shifts, a column per department, with the
 * volunteer's own departments offered first and shifts they may not see absent entirely.
 */
final class StaffApplyGridTest extends DatabaseTestCase
{
    private ?Group $staffGroup = null;

    private function grid(): StaffApplyGrid
    {
        return static::getContainer()->get(StaffApplyGrid::class);
    }

    private function staffGroup(): Group
    {
        if ($this->staffGroup === null) {
            $this->staffGroup = new Group('Staff', 'staff-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
            $this->em->persist($this->staffGroup);
        }

        return $this->staffGroup;
    }

    private function dept(string $name): Department
    {
        $d = new Department($name, strtolower($name).'-'.bin2hex(random_bytes(2)));
        $this->em->persist($d);

        return $d;
    }

    private function staffUser(): User
    {
        $u = new User();
        $u->setName('u'.bin2hex(random_bytes(3)))->setEmail(bin2hex(random_bytes(4)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $u->addGroup($this->staffGroup());
        $this->em->persist($u);

        return $u;
    }

    private function staffShift(Department $dept, VolunteerType $type, ShiftAudience $audience, string $start = '+1 day 10:00', string $end = '+1 day 12:00'): Shift
    {
        $shift = (new Shift())->setTitle('S '.bin2hex(random_bytes(2)))
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($dept)
            ->setAudience($audience)
            ->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($type, 2);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);

        return $shift;
    }

    private function confirmedMember(User $user, VolunteerType $type): void
    {
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
    }

    /** @return list<string> department names, in the order the picker offers them */
    private function departmentNames(array $grid): array
    {
        return array_map(static fn (array $option): string => $option['department']->getName(), $grid['departments']);
    }

    public function testTheVolunteersOwnDepartmentsComeFirstAndAreMarked(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $this->confirmedMember($user, $type);

        $mine = $this->dept('Logistics');
        $other = $this->dept('Bar');
        $user->assignGroup($this->staffGroup(), $mine);
        $this->staffShift($mine, $type, ShiftAudience::ALL_STAFF);
        $this->staffShift($other, $type, ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $grid = $this->grid()->build($user, null, false, []);

        self::assertSame(['Logistics', 'Bar'], $this->departmentNames($grid));
        self::assertTrue($grid['departments'][0]['member']);
        self::assertFalse($grid['departments'][1]['member']);
    }

    public function testTheMineOnlyFilterDropsDepartmentsTheVolunteerIsNotIn(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $this->confirmedMember($user, $type);

        $mine = $this->dept('Logistics');
        $user->assignGroup($this->staffGroup(), $mine);
        $this->staffShift($mine, $type, ShiftAudience::ALL_STAFF);
        $this->staffShift($this->dept('Bar'), $type, ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $grid = $this->grid()->build($user, null, true, []);

        self::assertSame(['Logistics'], $this->departmentNames($grid));
        self::assertCount(1, $grid['columns']);
    }

    public function testAPickedDepartmentIsTheOnlyColumnDrawn(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $this->confirmedMember($user, $type);

        $bar = $this->dept('Bar');
        $this->staffShift($this->dept('Logistics'), $type, ShiftAudience::ALL_STAFF);
        $this->staffShift($bar, $type, ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $grid = $this->grid()->build($user, null, false, [(string) $bar->getUuid()]);

        self::assertCount(1, $grid['columns']);
        self::assertSame('Bar', $grid['columns'][0]['department']->getName());
    }

    public function testADepartmentStaffShiftIsAbsentForNonMembers(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $this->confirmedMember($user, $type);

        $this->staffShift($this->dept('Tech'), $type, ShiftAudience::DEPARTMENT_STAFF);
        $this->em->flush();

        $grid = $this->grid()->build($user, null, false, []);

        self::assertSame([], $grid['departments'], 'a shift the volunteer may not see puts no department on the picker');
        self::assertSame([], $grid['columns']);
    }

    public function testABlockCarriesItsStaffingAndStatus(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $this->confirmedMember($user, $type);
        $this->staffShift($this->dept('Ops'), $type, ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $block = $this->grid()->build($user, null, false, [])['columns'][0]['blocks'][0];

        self::assertSame(2, $block['needed']);
        self::assertSame(0, $block['assigned']);
        self::assertSame('available', $block['status']);
        self::assertFalse($block['mine']);
    }

    /**
     * The axis starts at the first shift of the day rather than at midnight, so a day that runs from
     * the afternoon onwards does not open on eight empty hours.
     */
    public function testTheTimeAxisSpansTheDaysShiftsRoundedToWholeHours(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $this->confirmedMember($user, $type);

        $dept = $this->dept('Ops');
        $day = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $this->staffShift($dept, $type, ShiftAudience::ALL_STAFF, $day.' 14:30', $day.' 16:00');
        $this->staffShift($dept, $type, ShiftAudience::ALL_STAFF, $day.' 18:00', $day.' 20:15');
        $this->em->flush();

        $grid = $this->grid()->build($user, $day, false, []);

        self::assertSame(14 * 60, $grid['windowStart']);
        self::assertSame(21 * 60, $grid['windowEnd']);
        self::assertSame('14:00', $grid['hours'][0]['label']);
    }

    /** Parallel shifts in one department sit side by side rather than on top of each other. */
    public function testParallelShiftsShareTheDepartmentColumn(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $this->confirmedMember($user, $type);

        $dept = $this->dept('Ops');
        $day = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $this->staffShift($dept, $type, ShiftAudience::ALL_STAFF, $day.' 10:00', $day.' 12:00');
        $this->staffShift($dept, $type, ShiftAudience::ALL_STAFF, $day.' 10:00', $day.' 12:00');
        $this->staffShift($dept, $type, ShiftAudience::ALL_STAFF, $day.' 10:00', $day.' 12:00');
        $this->em->flush();

        $column = $this->grid()->build($user, $day, false, [])['columns'][0];

        self::assertSame(3, $column['lanes']);
        self::assertSame([0.0, 33.3333, 66.6666], array_column($column['blocks'], 'left'));
    }

    /** A day the volunteer did not ask for is not silently substituted for one with no shifts. */
    public function testAnUnknownDayFallsBackToTheFirstDayThatHasShifts(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $this->confirmedMember($user, $type);
        $this->staffShift($this->dept('Ops'), $type, ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $grid = $this->grid()->build($user, '1999-01-01', false, []);

        self::assertSame($grid['days'][0]['iso'], $grid['day']);
        self::assertNotEmpty($grid['columns']);
    }
}
