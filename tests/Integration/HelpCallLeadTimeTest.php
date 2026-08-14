<?php

namespace App\Tests\Integration;

use App\Service\Call\HelpCallService;
use App\Service\EventConfigStore;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * How early a manager may raise a call for help.
 *
 * `call.manager_lead` is stored, defaulted and labelled in seconds everywhere it is written, so it
 * has to be read as seconds too. Read as minutes it silently multiplies the window by sixty: the
 * configured five minutes becomes five hours, and a manager can call the whole event for help before
 * anybody is even due.
 */
final class HelpCallLeadTimeTest extends DatabaseTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );
    }

    private function calls(): HelpCallService
    {
        return static::getContainer()->get(HelpCallService::class);
    }

    private function setLead(int $seconds): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_CALL_MANAGER_LEAD, $seconds);
        $store->flush();
    }

    public function testAManagerCannotCallBeforeTheConfiguredWindowOpens(): void
    {
        $this->setLead(300);
        $shift = $this->scenario->shift('Gate', '+10 minutes', '+2 hours');
        $caller = $this->scenario->user(['call:trigger']);

        self::assertFalse(
            $this->calls()->canTriggerNow($caller, $shift, false, new \DateTimeImmutable()),
            'a 300-second lead opens five minutes before the shift, not five hours',
        );
    }

    public function testAManagerCanCallOnceTheWindowHasOpened(): void
    {
        $this->setLead(300);
        $shift = $this->scenario->shift('Gate', '+3 minutes', '+2 hours');
        $caller = $this->scenario->user(['call:trigger']);

        self::assertTrue($this->calls()->canTriggerNow($caller, $shift, false, new \DateTimeImmutable()));
    }

    /** A generous lead must still be honoured; the fix is the unit, not the size of the window. */
    public function testALongerLeadOpensTheWindowEarlier(): void
    {
        $this->setLead(7200);
        $shift = $this->scenario->shift('Gate', '+90 minutes', '+2 hours');
        $caller = $this->scenario->user(['call:trigger']);

        self::assertTrue($this->calls()->canTriggerNow($caller, $shift, false, new \DateTimeImmutable()));
    }

    public function testTheInfoDeskIsNotBoundByTheWindow(): void
    {
        $this->setLead(300);
        $shift = $this->scenario->shift('Gate', '+10 hours', '+2 hours');
        $caller = $this->scenario->user(['call:trigger']);

        self::assertTrue($this->calls()->canTriggerNow($caller, $shift, true, new \DateTimeImmutable()));
    }

    public function testNobodyMayCallForAShiftThatHasEnded(): void
    {
        $this->setLead(300);
        $shift = $this->scenario->shift('Gate', '-4 hours', '+2 hours');
        $caller = $this->scenario->user(['call:trigger']);

        self::assertFalse($this->calls()->canTriggerNow($caller, $shift, true, new \DateTimeImmutable()));
    }
}
