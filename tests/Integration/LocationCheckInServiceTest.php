<?php

namespace App\Tests\Integration;

use App\Entity\Group;
use App\Entity\PersonalData;
use App\Entity\User;
use App\Repository\LocationCheckInRepository;
use App\Service\EventConfigStore;
use App\Service\Security\LocationCheckInDecision;
use App\Service\Security\LocationCheckInService;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Who the security team may admit to the venue, and what admitting them records.
 *
 * The rules exist because a wristband is issued against a registration number and only to somebody
 * who has a reason to be on site. Staff run the event and are not rostered the way Critters are, so
 * they are not held to either condition.
 */
final class LocationCheckInServiceTest extends DatabaseTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function service(): LocationCheckInService
    {
        return static::getContainer()->get(LocationCheckInService::class);
    }

    private function volunteer(?int $badgeNumber): User
    {
        $user = new User();
        $user->setName('critter-'.bin2hex(random_bytes(3)))
            ->setEmail('critter-'.bin2hex(random_bytes(3)).'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('x');
        if ($badgeNumber !== null) {
            $personal = new PersonalData($user);
            $personal->setBadgeNumber($badgeNumber);
            $user->setPersonalData($personal);
            $this->em->persist($personal);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function staffMember(): User
    {
        $group = new Group('Door staff', 'door-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        $this->em->persist($group);

        $user = $this->volunteer(null);
        $user->addGroup($group);
        $this->em->flush();

        return $user;
    }

    /** Assigns the user to a shift running between the two offsets, so eligibility has something to find. */
    private function shiftFor(User $user, string $startsIn, string $endsIn): void
    {
        $shift = $this->scenario->shift('Door duty', 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt(new \DateTimeImmutable($startsIn))->setEndsAt(new \DateTimeImmutable($endsIn));
        $this->scenario->signUp($user, $shift);
        $this->em->flush();
    }

    public function testStaffAreAdmittedWithNoBadgeAndNoShift(): void
    {
        self::assertTrue($this->service()->decide($this->staffMember())->allowed);
    }

    public function testAVolunteerWithoutARegistrationNumberIsRefused(): void
    {
        $user = $this->volunteer(null);
        $this->shiftFor($user, '+30 minutes', '+4 hours');

        $decision = $this->service()->decide($user);

        self::assertFalse($decision->allowed);
        self::assertTrue($decision->refusedFor(LocationCheckInDecision::REASON_NO_REGISTRATION));
    }

    public function testAVolunteerWithoutAShiftIsRefused(): void
    {
        $decision = $this->service()->decide($this->volunteer(4242));

        self::assertFalse($decision->allowed);
        self::assertTrue($decision->refusedFor(LocationCheckInDecision::REASON_NO_SHIFT));
    }

    public function testAShiftStartingInsideTheWindowAdmits(): void
    {
        $user = $this->volunteer(4242);
        $this->shiftFor($user, '+30 minutes', '+4 hours');

        self::assertTrue($this->service()->decide($user)->allowed);
    }

    /** Somebody arriving late is still due at the venue, so a shift already under way qualifies. */
    public function testAShiftAlreadyRunningAdmits(): void
    {
        $user = $this->volunteer(4242);
        $this->shiftFor($user, '-30 minutes', '+2 hours');

        self::assertTrue($this->service()->decide($user)->allowed);
    }

    public function testAShiftThatHasEndedDoesNotAdmit(): void
    {
        $user = $this->volunteer(4242);
        $this->shiftFor($user, '-5 hours', '-1 hour');

        self::assertFalse($this->service()->decide($user)->allowed);
    }

    public function testAShiftBeyondTheWindowDoesNotAdmit(): void
    {
        $user = $this->volunteer(4242);
        $this->shiftFor($user, '+6 hours', '+9 hours');

        self::assertFalse($this->service()->decide($user)->allowed);
    }

    /** The window is configuration, not a constant, so widening it changes who is admitted. */
    public function testWideningTheWindowAdmitsAShiftThatWasTooFarOff(): void
    {
        $user = $this->volunteer(4242);
        $this->shiftFor($user, '+6 hours', '+9 hours');
        self::assertFalse($this->service()->decide($user)->allowed);

        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_SECURITY_CHECKIN_WINDOW, 8 * 3600);
        $store->flush();

        self::assertTrue($this->service()->decide($user)->allowed);
    }

    public function testEnteringRecordsWhoAdmittedThem(): void
    {
        $user = $this->volunteer(4242);
        $this->shiftFor($user, '+30 minutes', '+4 hours');
        $operator = $this->staffMember();

        $row = $this->service()->enter($user, $operator);

        self::assertTrue($row->isEntry());
        self::assertSame($operator->getId(), $row->getActor()?->getId());
        self::assertFalse($row->isOverridden());
        self::assertTrue($this->service()->isInside($user));
    }

    public function testARefusedEntryNeedsAnOverrideReason(): void
    {
        $user = $this->volunteer(null);

        $this->expectException(\RuntimeException::class);
        $this->service()->enter($user, $this->staffMember());
    }

    public function testAnOverrideIsRecordedWithItsReason(): void
    {
        $user = $this->volunteer(null);

        $row = $this->service()->enter($user, $this->staffMember(), 'Known to the team, badge issued by hand');

        self::assertTrue($row->isOverridden());
        self::assertSame('Known to the team, badge issued by hand', $row->getOverrideReason());
        self::assertTrue($this->service()->isInside($user));
    }

    /** Withdrawing appends: the entry it takes back is still in the record afterwards. */
    public function testWithdrawingAppendsRatherThanDeletes(): void
    {
        $user = $this->volunteer(4242);
        $this->shiftFor($user, '+30 minutes', '+4 hours');

        $this->service()->enter($user, null);
        $this->service()->withdraw($user, null);

        self::assertFalse($this->service()->isInside($user));
        self::assertCount(2, static::getContainer()->get(LocationCheckInRepository::class)->findHistoryForUser($user));
    }

    /** Coming back after being withdrawn is a third row, and puts the person inside again. */
    public function testASecondEntryOnTheSameDayIsAnotherRow(): void
    {
        $user = $this->volunteer(4242);
        $this->shiftFor($user, '+30 minutes', '+4 hours');

        $this->service()->enter($user, null);
        $this->service()->withdraw($user, null);
        $this->service()->enter($user, null);

        self::assertTrue($this->service()->isInside($user));
        self::assertCount(3, static::getContainer()->get(LocationCheckInRepository::class)->findHistoryForUser($user));
    }
}
