<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\State;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Service\Shift\CheckInPolicy;
use App\Tests\DatabaseTestCase;

/**
 * Event-phase check-in rules: setup/teardown are exempt, the main
 * event requires check-in, and the per-shift override forces it in every phase.
 */
final class CheckInPolicyTest extends DatabaseTestCase
{
    private function policy(): CheckInPolicy
    {
        return static::getContainer()->get(CheckInPolicy::class);
    }

    private function configureEventDates(): void
    {
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_EVENT_START, '2026-06-05 00:00:00');
        $config->set(EventConfigStore::KEY_EVENT_END, '2026-06-08 00:00:00');
    }

    private function shift(string $start, bool $override = false): Shift
    {
        $suffix = bin2hex(random_bytes(4));
        $dept = new Department('D '.$suffix, 'd-'.$suffix);
        $this->em->persist($dept);
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt((new \DateTimeImmutable($start))->modify('+2 hours'))
            ->setDepartment($dept)
            ->setRequireCheckin($override);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    public function testSetupAndTeardownAreExempt(): void
    {
        $this->configureEventDates();
        $setup = $this->shift('2026-06-04 10:00');   // before event start
        $teardown = $this->shift('2026-06-09 10:00'); // after event end

        self::assertSame(CheckInPolicy::PHASE_SETUP, $this->policy()->phaseOf($setup));
        self::assertSame(CheckInPolicy::PHASE_TEARDOWN, $this->policy()->phaseOf($teardown));
        self::assertFalse($this->policy()->requiresCheckin($setup));
        self::assertFalse($this->policy()->requiresCheckin($teardown));
    }

    public function testMainEventRequiresCheckin(): void
    {
        $this->configureEventDates();
        $main = $this->shift('2026-06-06 10:00');

        self::assertSame(CheckInPolicy::PHASE_MAIN, $this->policy()->phaseOf($main));
        self::assertTrue($this->policy()->requiresCheckin($main));
    }

    public function testOverrideForcesCheckinDuringSetup(): void
    {
        $this->configureEventDates();
        $setupOverride = $this->shift('2026-06-04 10:00', override: true);

        self::assertSame(CheckInPolicy::PHASE_SETUP, $this->policy()->phaseOf($setupOverride));
        self::assertTrue($this->policy()->requiresCheckin($setupOverride));
    }

    public function testUnknownPhaseIsExemptWithoutOverride(): void
    {
        // No event dates configured -> phase null -> only the override gates.
        $shift = $this->shift('2026-06-06 10:00');
        self::assertNull($this->policy()->phaseOf($shift));
        self::assertFalse($this->policy()->requiresCheckin($shift));
    }

    public function testCheckInErrorReflectsArrivalState(): void
    {
        $this->configureEventDates();
        $main = $this->shift('2026-06-06 10:00');

        $user = new User();
        $user->setName('vol')->setEmail('vol@e.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        self::assertNotNull($this->policy()->checkInError($main, $user), 'not-arrived user is blocked');

        $state = new State($user);
        $state->setArrived(true);
        $user->setState($state);
        $this->em->persist($state);
        $this->em->flush();

        self::assertNull($this->policy()->checkInError($main, $user), 'arrived user may apply');
    }
}
