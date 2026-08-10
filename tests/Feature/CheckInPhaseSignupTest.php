<?php

namespace App\Tests\Feature;

use App\Entity\Shift;
use App\Entity\State;
use App\Entity\User;
use App\Repository\ShiftEntryRepository;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Event check-in gates shift application during the main event only.
 *
 * Setup and teardown run when the Info Desk that performs check-in may not be staffed at all, so a
 * volunteer who has not arrived yet must still be able to take those shifts - otherwise nobody can
 * be rostered to build the event. A shift that marks itself "require check-in" overrides that in
 * every phase, for work where identity has to be confirmed first.
 *
 * CheckInPolicy is unit-tested; this drives the rule through the real sign-up request, which is
 * where it actually has to hold.
 */
final class CheckInPhaseSignupTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));

        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_EVENT_START, '2027-06-05 00:00:00');
        $config->set(EventConfigStore::KEY_EVENT_END, '2027-06-08 00:00:00');
        $config->flush();
    }

    private function entries(): ShiftEntryRepository
    {
        return static::getContainer()->get(ShiftEntryRepository::class);
    }

    /** A confirmed member who has not been checked in at the Info Desk. */
    private function notArrivedVolunteer(): User
    {
        return $this->scenario->user(memberOf: $this->scenario->type);
    }

    private function arrive(User $user): void
    {
        $state = $user->getState() ?? new State($user);
        $state->setArrived(true);
        $user->setState($state);
        $this->em->persist($state);
        $this->em->flush();
    }

    /** Submits the card's sign-up form the way the browse page renders it. */
    private function applyTo(Shift $shift): void
    {
        $crawler = $this->client->request('GET', '/shifts?date='.$shift->getStartsAt()->format('Y-m-d'));
        $form = $crawler->filter('form[action*="/signup"]')->form();

        $this->client->request('POST', $form->getUri(), $form->getPhpValues() + [
            'group_type' => [(string) $shift->getUuid() => (string) $this->scenario->type->getUuid()],
        ]);
    }

    public function testASetupShiftAcceptsAVolunteerWhoHasNotCheckedIn(): void
    {
        $shift = $this->scenario->shift('Build The Stage', '2027-06-04 10:00');
        $user = $this->notArrivedVolunteer();
        $this->client->loginUser($user);

        $this->applyTo($shift);

        self::assertNotNull(
            $this->entries()->findOneByShiftAndUser($shift, $user),
            'a shift before the event starts must not wait for a check-in that cannot happen yet',
        );
    }

    public function testATeardownShiftAcceptsAVolunteerWhoHasNotCheckedIn(): void
    {
        $shift = $this->scenario->shift('Strike The Stage', '2027-06-09 10:00');
        $user = $this->notArrivedVolunteer();
        $this->client->loginUser($user);

        $this->applyTo($shift);

        self::assertNotNull(
            $this->entries()->findOneByShiftAndUser($shift, $user),
            'a shift after the event ends must not require check-in either',
        );
    }

    public function testAMainEventShiftIsRefusedUntilTheVolunteerHasCheckedIn(): void
    {
        $shift = $this->scenario->shift('Main Gate', '2027-06-06 10:00');
        $user = $this->notArrivedVolunteer();
        $this->client->loginUser($user);

        $this->applyTo($shift);

        self::assertNull(
            $this->entries()->findOneByShiftAndUser($shift, $user),
            'the main event still requires check-in',
        );
    }

    public function testAMainEventShiftIsAcceptedOnceTheVolunteerHasCheckedIn(): void
    {
        $shift = $this->scenario->shift('Main Gate', '2027-06-06 10:00');
        $user = $this->notArrivedVolunteer();
        $this->arrive($user);
        $this->client->loginUser($user);

        $this->applyTo($shift);

        self::assertNotNull($this->entries()->findOneByShiftAndUser($shift, $user));
    }

    public function testTheRequireCheckinOverrideStillGatesASetupShift(): void
    {
        $shift = $this->scenario->shift('Cash Handling', '2027-06-04 10:00');
        $shift->setRequireCheckin(true);
        $this->em->flush();

        $user = $this->notArrivedVolunteer();
        $this->client->loginUser($user);

        $this->applyTo($shift);

        self::assertNull(
            $this->entries()->findOneByShiftAndUser($shift, $user),
            'a shift that asks for check-in explicitly must be gated in setup too',
        );
    }

    public function testTheRequireCheckinOverrideStillGatesATeardownShift(): void
    {
        $shift = $this->scenario->shift('Cash Handling', '2027-06-09 10:00');
        $shift->setRequireCheckin(true);
        $this->em->flush();

        $user = $this->notArrivedVolunteer();
        $this->client->loginUser($user);

        $this->applyTo($shift);

        self::assertNull(
            $this->entries()->findOneByShiftAndUser($shift, $user),
            'a shift that asks for check-in explicitly must be gated in teardown too',
        );
    }
}
