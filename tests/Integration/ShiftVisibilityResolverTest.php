<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Service\Shift\ShiftVisibilityResolver;
use App\Tests\DatabaseTestCase;

/**
 * Audience visibility matrix: a staff-only shift is never exposed
 * to a volunteer, and draft shifts are invisible to everyone in browsing.
 */
final class ShiftVisibilityResolverTest extends DatabaseTestCase
{
    private ?Group $staffGroup = null;

    private function resolver(): ShiftVisibilityResolver
    {
        return static::getContainer()->get(ShiftVisibilityResolver::class);
    }

    private function staffGroup(): Group
    {
        if ($this->staffGroup === null) {
            $this->staffGroup = new Group('Staff', 'staff-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
            $this->em->persist($this->staffGroup);
        }

        return $this->staffGroup;
    }

    private function makeUser(string $name, bool $staff = false): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        if ($staff) {
            $user->addGroup($this->staffGroup());
        }
        $this->em->persist($user);

        return $user;
    }

    private function makeDept(string $name): Department
    {
        $d = new Department($name, strtolower($name).'-'.bin2hex(random_bytes(2)));
        $this->em->persist($d);

        return $d;
    }

    private function makeShift(Department $dept, ShiftAudience $audience, ShiftState $state = ShiftState::PUBLISHED): Shift
    {
        $shift = (new Shift())
            ->setTitle('Shift')
            ->setStartsAt(new \DateTimeImmutable('+1 day'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 2 hours'))
            ->setDepartment($dept)
            ->setAudience($audience)
            ->setState($state);
        $this->em->persist($shift);

        return $shift;
    }

    private function makeMember(User $user, Department $dept): void
    {
        // A department-scoped assignment is this user's membership of that department.
        $user->assignGroup($this->staffGroup(), $dept);
    }

    public function testPublicShiftVisibleToVolunteerAndStaff(): void
    {
        $dept = $this->makeDept('Logistics');
        $volunteer = $this->makeUser('vol');
        $staff = $this->makeUser('stf', true);
        $shift = $this->makeShift($dept, ShiftAudience::PUBLIC_VOLUNTEER);
        $this->em->flush();

        self::assertTrue($this->resolver()->isVisibleTo($shift, $volunteer));
        self::assertTrue($this->resolver()->isVisibleTo($shift, $staff));
        self::assertTrue($this->resolver()->isVisibleTo($shift, null));
    }

    public function testStaffOnlyAudiencesNeverVisibleToVolunteers(): void
    {
        $dept = $this->makeDept('Stage');
        $volunteer = $this->makeUser('vol');
        $this->em->flush();

        foreach ([ShiftAudience::ALL_STAFF, ShiftAudience::DEPARTMENT_STAFF, ShiftAudience::INVITE_ONLY] as $audience) {
            $shift = $this->makeShift($dept, $audience);
            $this->em->flush();
            self::assertFalse(
                $this->resolver()->isVisibleTo($shift, $volunteer),
                $audience->value.' must not be visible to a volunteer'
            );
            self::assertTrue($this->resolver()->isStaffOnly($shift));
        }
    }

    public function testAllStaffVisibleToAnyStaff(): void
    {
        $dept = $this->makeDept('Ops');
        $staff = $this->makeUser('stf', true);
        $shift = $this->makeShift($dept, ShiftAudience::ALL_STAFF);
        $this->em->flush();

        self::assertTrue($this->resolver()->isVisibleTo($shift, $staff));
    }

    public function testDepartmentStaffVisibleOnlyToMembers(): void
    {
        $dept = $this->makeDept('Tech');
        $other = $this->makeDept('Bar');
        $member = $this->makeUser('member', true);
        $outsider = $this->makeUser('outsider', true);
        $shift = $this->makeShift($dept, ShiftAudience::DEPARTMENT_STAFF);
        $this->makeMember($member, $dept);
        $this->makeMember($outsider, $other);
        $this->em->flush();

        self::assertTrue($this->resolver()->isVisibleTo($shift, $member));
        self::assertFalse($this->resolver()->isVisibleTo($shift, $outsider));
    }

    public function testInviteOnlyVisibleOnlyThroughAssignment(): void
    {
        $dept = $this->makeDept('VIP');
        $invited = $this->makeUser('invited', true);
        $stranger = $this->makeUser('stranger', true);
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $shift = $this->makeShift($dept, ShiftAudience::INVITE_ONLY);
        $this->em->persist(new ShiftEntry($shift, $type, $invited));
        $this->em->flush();

        self::assertTrue($this->resolver()->isVisibleTo($shift, $invited));
        self::assertFalse($this->resolver()->isVisibleTo($shift, $stranger));
    }

    public function testDraftNeverVisibleEvenWhenPublicAudience(): void
    {
        $dept = $this->makeDept('Draft');
        $volunteer = $this->makeUser('vol');
        $staff = $this->makeUser('stf', true);
        $shift = $this->makeShift($dept, ShiftAudience::PUBLIC_VOLUNTEER, ShiftState::DRAFT);
        $this->em->flush();

        self::assertFalse($this->resolver()->isVisibleTo($shift, $volunteer));
        self::assertFalse($this->resolver()->isVisibleTo($shift, $staff));
    }
}
