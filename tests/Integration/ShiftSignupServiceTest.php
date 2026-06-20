<?php

namespace App\Tests\Integration;

use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Service\ShiftSignupService;
use App\Tests\DatabaseTestCase;

final class ShiftSignupServiceTest extends DatabaseTestCase
{
    private function service(): ShiftSignupService
    {
        return static::getContainer()->get(ShiftSignupService::class);
    }

    private function makeUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function makeType(string $name): VolunteerType
    {
        $type = new VolunteerType($name);
        $this->em->persist($type);

        return $type;
    }

    private function confirmMember(User $user, VolunteerType $type): void
    {
        $membership = new UserVolunteerType($user, $type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
    }

    private function makeShift(string $start, string $end, VolunteerType $type, int $needed = 1): Shift
    {
        $shift = (new Shift())
            ->setTitle('Shift')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end));
        $this->em->persist($shift);

        $need = new NeededVolunteerType($type, $needed);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);

        return $shift;
    }

    public function testConfirmedMemberCanSignUpAndDuplicateIsRejected(): void
    {
        $type = $this->makeType('Logistics');
        $user = $this->makeUser('alice');
        $this->confirmMember($user, $type);
        $shift = $this->makeShift('+1 day 10:00', '+1 day 14:00', $type);
        $this->em->flush();

        self::assertNull($this->service()->signUpError($user, $shift, $type));

        $this->service()->signUp($user, $shift, $type);
        self::assertCount(1, $this->em->getRepository(ShiftEntry::class)->findAll());

        self::assertSame('You are already signed up for this shift.', $this->service()->signUpError($user, $shift, $type));
    }

    public function testEligibilityStatusReflectsTheUserState(): void
    {
        $service = $this->service();
        $type = $this->makeType('Greeter');
        $member = $this->makeUser('mona');
        $stranger = $this->makeUser('stan');
        $this->confirmMember($member, $type);
        $shift = $this->makeShift('+1 day 10:00', '+1 day 14:00', $type, 1);
        $this->em->flush();

        self::assertSame('available', $service->eligibilityStatus($shift, $member));
        self::assertSame('ineligible', $service->eligibilityStatus($shift, $stranger));
        self::assertArrayHasKey($type->getId(), $service->signupOptions($shift, $member));

        $service->signUp($member, $shift, $type);
        self::assertSame('signed_up', $service->eligibilityStatus($shift, $member));
        self::assertSame([], $service->signupOptions($shift, $member));
        // Role now full for everyone else.
        self::assertSame('full', $service->eligibilityStatus($shift, $stranger));
    }

    public function testPastShiftStatusIsPast(): void
    {
        $type = $this->makeType('Teardown');
        $user = $this->makeUser('paul');
        $this->confirmMember($user, $type);
        $shift = $this->makeShift('-2 day 10:00', '-2 day 14:00', $type);
        $this->em->flush();

        self::assertSame('past', $this->service()->eligibilityStatus($shift, $user));
    }

    public function testNonMemberIsRejected(): void
    {
        $type = $this->makeType('Security');
        $user = $this->makeUser('bob');
        $shift = $this->makeShift('+1 day 10:00', '+1 day 14:00', $type);
        $this->em->flush();

        self::assertSame('You are not a confirmed member of this volunteer type.', $this->service()->signUpError($user, $shift, $type));
    }

    public function testPastShiftIsRejected(): void
    {
        $type = $this->makeType('Cleanup');
        $user = $this->makeUser('carol');
        $this->confirmMember($user, $type);
        $shift = $this->makeShift('-2 day 10:00', '-2 day 14:00', $type);
        $this->em->flush();

        self::assertSame('This shift has already ended.', $this->service()->signUpError($user, $shift, $type));
    }

    public function testFullRoleIsRejected(): void
    {
        $type = $this->makeType('Door');
        $first = $this->makeUser('dora');
        $second = $this->makeUser('erin');
        $this->confirmMember($first, $type);
        $this->confirmMember($second, $type);
        $shift = $this->makeShift('+1 day 10:00', '+1 day 14:00', $type, 1);
        $this->em->flush();

        $this->service()->signUp($first, $shift, $type);

        self::assertSame('This role is already fully staffed.', $this->service()->signUpError($second, $shift, $type));
    }

    public function testOverlappingShiftIsRejected(): void
    {
        $type = $this->makeType('Roaming');
        $user = $this->makeUser('finn');
        $this->confirmMember($user, $type);
        $a = $this->makeShift('+1 day 10:00', '+1 day 14:00', $type);
        $b = $this->makeShift('+1 day 12:00', '+1 day 16:00', $type);
        $this->em->flush();

        $this->service()->signUp($user, $a, $type);

        self::assertSame('You are already booked for an overlapping shift.', $this->service()->signUpError($user, $b, $type));
    }
}
