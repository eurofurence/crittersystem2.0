<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftEntryState;
use App\Service\Call\HelpCallService;
use App\Tests\DatabaseTestCase;

/**
 * What the bounty board offers, now that answering a call no longer requires opting in first.
 *
 * Being marked Free to help used to be a precondition, so the board was empty for everybody who had
 * not set it, which is most people: calls went unanswered while volunteers who would gladly have
 * gone saw nothing at all. The status now decides who is interrupted, not who is allowed.
 *
 * Everything else that filtered a call out still does. The one exclusion that remains from the
 * status is somebody already working a shift, because that status is derived from the shift rather
 * than chosen by them.
 */
final class BountyBoardVisibilityTest extends DatabaseTestCase
{
    private VolunteerType $type;
    private Department $department;
    private Group $staffGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = new Department('Ops '.bin2hex(random_bytes(3)), 'ops-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->department);
        $this->type = new VolunteerType('Crew '.bin2hex(random_bytes(3)));
        $this->em->persist($this->type);
        $this->staffGroup = new Group('Staff', 'staff-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        $this->em->persist($this->staffGroup);
        $this->em->flush();
    }

    private function calls(): HelpCallService
    {
        return static::getContainer()->get(HelpCallService::class);
    }

    /** A confirmed member of the needed type who has set no operational status at all. */
    private function member(string $name): User
    {
        $user = new User();
        $user->setName($name.bin2hex(random_bytes(2)))
            ->setEmail($name.bin2hex(random_bytes(2)).'@e.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x');
        $user->addGroup($this->staffGroup);
        $this->em->persist($user);

        $membership = new UserVolunteerType($user, $this->type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    private function shift(string $title, string $startsAt, string $endsAt): Shift
    {
        $shift = (new Shift())->setTitle($title)
            ->setStartsAt(new \DateTimeImmutable($startsAt))
            ->setEndsAt(new \DateTimeImmutable($endsAt))
            ->setDepartment($this->department);
        $this->em->persist($shift);
        $need = new NeededVolunteerType($this->type, 1);
        $shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $this->em->flush();

        return $shift;
    }

    public function testACallIsOfferedToSomebodyWhoNeverMarkedThemselvesFreeToHelp(): void
    {
        $user = $this->member('willing');
        $this->calls()->trigger($this->shift('Gate', '+1 hour', '+3 hours'), null, 1);

        self::assertCount(1, $this->calls()->eligibleActiveCalls($user));
    }

    /**
     * Somebody mid-shift is left out. Their status is derived from the shift rather than chosen,
     * and a call is not a reason to walk away from what they are already doing.
     */
    public function testSomebodyCurrentlyWorkingAShiftIsNotOffered(): void
    {
        $user = $this->member('onduty');

        $running = $this->shift('Running', '-1 hour', '+1 hour');
        $entry = new ShiftEntry($running, $this->type, $user);
        $entry->setState(ShiftEntryState::ASSIGNMENT);
        $this->em->persist($entry);
        $this->em->flush();

        $this->calls()->trigger($this->shift('Gate', '+4 hours', '+6 hours'), null, 1);

        self::assertSame([], $this->calls()->eligibleActiveCalls($user));
    }

    /** The board answers who is needed next, so the nearest shift leads regardless of call order. */
    public function testCallsAreOrderedBySoonestShiftNotByWhenTheyWereRaised(): void
    {
        $user = $this->member('willing');

        $later = $this->shift('Later', '+8 hours', '+10 hours');
        $sooner = $this->shift('Sooner', '+1 hour', '+2 hours');

        $this->calls()->trigger($later, null, 1);
        $this->calls()->trigger($sooner, null, 1);

        $titles = array_map(
            static fn ($call): string => $call->getShift()->getTitle(),
            $this->calls()->eligibleActiveCalls($user),
        );

        self::assertSame(['Sooner', 'Later'], $titles);
    }

    /** Everything other than the status still filters: a type the person does not hold is not offered. */
    public function testACallForATypeTheUserDoesNotHoldIsStillNotOffered(): void
    {
        $outsider = new User();
        $outsider->setName('outsider'.bin2hex(random_bytes(2)))
            ->setEmail('outsider'.bin2hex(random_bytes(2)).'@e.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x');
        $outsider->addGroup($this->staffGroup);
        $this->em->persist($outsider);
        $this->em->flush();

        $this->calls()->trigger($this->shift('Gate', '+1 hour', '+3 hours'), null, 1);

        self::assertSame([], $this->calls()->eligibleActiveCalls($outsider));
    }
}
