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
use App\Service\Shift\StaffApplicationService;
use App\Tests\DatabaseTestCase;

/**
 * Staff shift application listing: applicable staff shifts are
 * grouped into the user's departments and other viewable departments, with live
 * capacity per shift.
 */
final class StaffApplicationServiceTest extends DatabaseTestCase
{
    private ?Group $staffGroup = null;

    private function service(): StaffApplicationService
    {
        return static::getContainer()->get(StaffApplicationService::class);
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

    private function staffShift(Department $dept, VolunteerType $type, ShiftAudience $audience): Shift
    {
        $shift = (new Shift())->setTitle('S '.bin2hex(random_bytes(2)))
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($dept)
            ->setAudience($audience)
            ->setState(ShiftState::PUBLISHED);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($type, 2);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);

        return $shift;
    }

    public function testShiftsGroupedByMembership(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);

        $mine = $this->dept('Logistics');
        $other = $this->dept('Bar');
        // Member of "mine" only (department-scoped assignment).
        $user->assignGroup($this->staffGroup(), $mine);

        // Both departments run all-staff shifts the user is eligible for.
        $this->staffShift($mine, $type, ShiftAudience::ALL_STAFF);
        $this->staffShift($other, $type, ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $groups = $this->service()->departmentGroups($user);

        $memberNames = array_map(static fn ($g) => $g['department']->getName(), $groups['member']);
        $otherNames = array_map(static fn ($g) => $g['department']->getName(), $groups['other']);
        self::assertContains('Logistics', $memberNames);
        self::assertContains('Bar', $otherNames);
    }

    public function testDepartmentStaffShiftHiddenFromNonMembers(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);

        $foreign = $this->dept('Tech');
        // A department-staff shift in a department the user is not a member of.
        $this->staffShift($foreign, $type, ShiftAudience::DEPARTMENT_STAFF);
        $this->em->flush();

        $groups = $this->service()->departmentGroups($user);
        self::assertSame([], $groups['member']);
        self::assertSame([], $groups['other'], 'department-staff shift is not visible to a non-member');
    }

    public function testShiftRowCarriesLiveCapacity(): void
    {
        $user = $this->staffUser();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $dept = $this->dept('Ops');
        $shift = $this->staffShift($dept, $type, ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $row = $this->service()->shiftRow($shift, $user);
        self::assertSame(2, $row['needed']);
        self::assertSame(0, $row['assigned']);
    }
}
