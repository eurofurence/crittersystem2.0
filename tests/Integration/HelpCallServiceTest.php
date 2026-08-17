<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\OperationalStatusOverride;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\HelpCallStatus;
use App\Service\Call\HelpCallService;
use App\Service\OperationalStatusService;
use App\Tests\DatabaseTestCase;

/**
 * Global Call for Help: accepting fills a slot transactionally, an
 * accept after the target is full is refused, refusal suppresses eligibility,
 * and cancel/expire close the call.
 */
final class HelpCallServiceTest extends DatabaseTestCase
{
    private VolunteerType $type;
    private Shift $shift;
    private Group $staffGroup;

    private function service(): HelpCallService
    {
        return static::getContainer()->get(HelpCallService::class);
    }

    /** The shared shift is public and needs the volunteer type, so eligible members can see and accept a call on it. */
    protected function setUp(): void
    {
        parent::setUp();
        $dept = new Department('Ops '.bin2hex(random_bytes(3)), 'ops-'.bin2hex(random_bytes(3)));
        $this->em->persist($dept);
        $this->type = new VolunteerType('Crew '.bin2hex(random_bytes(3)));
        $this->em->persist($this->type);
        $this->staffGroup = new Group('Staff', 'staff-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        $this->em->persist($this->staffGroup);
        $this->shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable('+1 hour'))
            ->setEndsAt(new \DateTimeImmutable('+3 hours'))
            ->setDepartment($dept);
        $this->em->persist($this->shift);
        $need = new NeededVolunteerType($this->type, 1);
        $this->shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $this->em->flush();
    }

    /** A confirmed member who is Free to help. */
    private function freeMember(string $name): User
    {
        $u = new User();
        $u->setName($name)->setEmail($name.bin2hex(random_bytes(2)).'@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $u->addGroup($this->staffGroup);
        $this->em->persist($u);
        $m = new UserVolunteerType($u, $this->type);
        $m->setConfirmedBy($u);
        $this->em->persist($m);
        $override = new OperationalStatusOverride($u, OperationalStatusService::FREE_TO_HELP, new \DateTimeImmutable('+2 hours'));
        $this->em->persist($override);
        $this->em->flush();

        return $u;
    }

    public function testAcceptFillsSlotAndAcceptAfterFullIsRefused(): void
    {
        $call = $this->service()->trigger($this->shift, null, 1);
        $first = $this->freeMember('first');
        $second = $this->freeMember('second');

        $this->service()->accept($call, $first);
        self::assertSame(HelpCallStatus::FILLED, $call->getStatus());
        self::assertSame(0, $call->slotsRemaining());
        self::assertNotNull($this->em->getRepository(ShiftEntry::class)->findOneByShiftAndUser($this->shift, $first));

        $this->expectException(\RuntimeException::class);
        $this->service()->accept($call, $second);
    }

    public function testRefusalSuppressesEligibility(): void
    {
        $call = $this->service()->trigger($this->shift, null, 2);
        $user = $this->freeMember('refuser');

        self::assertTrue($this->service()->isEligible($call, $user));
        $this->service()->refuse($call, $user);
        self::assertFalse($this->service()->isEligible($call, $user), 'a refuser is no longer eligible');
    }

    public function testCancelClosesTheCall(): void
    {
        $call = $this->service()->trigger($this->shift, null, 1);
        $this->service()->cancel($call);
        self::assertSame(HelpCallStatus::CANCELLED, $call->getStatus());
        self::assertFalse($this->service()->isEligible($call, $this->freeMember('x')));
    }

    public function testExpireWhenShiftEnded(): void
    {
        $call = $this->service()->trigger($this->shift, null, 1);
        self::assertTrue($this->service()->expireIfDue($call, new \DateTimeImmutable('+1 day')));
        self::assertSame(HelpCallStatus::EXPIRED, $call->getStatus());
    }

    /** A confirmed member of the needed type is still ineligible without a free-to-help override. */
    public function testNotFreeToHelpIsIneligible(): void
    {
        $call = $this->service()->trigger($this->shift, null, 1);
        $u = new User();
        $u->setName('busy')->setEmail('busy@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $u->addGroup($this->staffGroup);
        $this->em->persist($u);
        $m = new UserVolunteerType($u, $this->type);
        $m->setConfirmedBy($u);
        $this->em->persist($m);
        $this->em->flush();

        self::assertFalse($this->service()->isEligible($call, $u));
    }
}
