<?php

namespace App\Tests\Integration;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Mercure\Topics;
use App\Mercure\UpdatePublisher;
use App\Service\Board\BoardSettings;
use App\Service\Call\HelpCallService;
use App\Service\DutyService;
use App\Service\EventConfigStore;
use App\Service\Shift\ShiftAttendanceService;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\RecordedUpdates;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The changes the operations board depends on, and whether anything is told about them.
 *
 * The board watches its department's topic and re-fetches when it hears anything. Three of the
 * things it reports were silent before it existed: going on and off duty, being checked in against a
 * shift, and a call for help being raised. Each of them leaves a board showing something that is no
 * longer true, on a screen nobody is touching - which is the one failure a wall display cannot
 * report for itself.
 */
final class LiveBoardSignalTest extends DatabaseTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );
        RecordedUpdates::clear();
    }

    private function flush(): void
    {
        static::getContainer()->get(UpdatePublisher::class)->flush();
    }

    private function departmentTopic(): string
    {
        return Topics::departmentShifts($this->scenario->department);
    }

    public function testGoingOnDutyTellsTheDepartment(): void
    {
        $duty = static::getContainer()->get(DutyService::class);
        $volunteer = $this->scenario->user();

        $duty->startDuty($volunteer, $this->scenario->department);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic($this->departmentTopic()));
    }

    public function testGoingOffDutyTellsTheDepartment(): void
    {
        $duty = static::getContainer()->get(DutyService::class);
        $volunteer = $this->scenario->user();
        $duty->startDuty($volunteer, $this->scenario->department);
        $this->flush();
        RecordedUpdates::clear();

        $duty->endDuty($volunteer);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic($this->departmentTopic()));
    }

    /** A duty session with no department has nobody to tell, and must not fail trying. */
    public function testADutyWithoutADepartmentSignalsNothing(): void
    {
        $duty = static::getContainer()->get(DutyService::class);

        $duty->startDuty($this->scenario->user(), null);
        $this->flush();

        self::assertSame([], RecordedUpdates::forTopic($this->departmentTopic()));
    }

    public function testCheckingSomebodyInTellsTheDepartment(): void
    {
        $attendance = static::getContainer()->get(ShiftAttendanceService::class);
        $shift = $this->scenario->shift('Gate');
        $entry = $this->scenario->signUp($this->scenario->user(), $shift);
        RecordedUpdates::clear();

        $attendance->checkIn($entry);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic($this->departmentTopic()));
    }

    public function testCheckingSomebodyOutTellsTheDepartment(): void
    {
        $attendance = static::getContainer()->get(ShiftAttendanceService::class);
        $shift = $this->scenario->shift('Gate');
        $entry = $this->scenario->signUp($this->scenario->user(), $shift);
        $attendance->checkIn($entry);
        $this->flush();
        RecordedUpdates::clear();

        $attendance->checkOut($entry);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic($this->departmentTopic()));
    }

    /**
     * The call fan-out addresses each eligible volunteer's own topic, which no board subscribes to,
     * so without this the shift's row would keep offering a button for a call already raised.
     */
    public function testRaisingACallTellsTheDepartment(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_CALL_MANAGER_LEAD, 3600);
        $store->flush();

        $shift = $this->scenario->shift('Gate', '+20 minutes', '+3 hours');
        $caller = $this->scenario->user(['call:trigger']);
        RecordedUpdates::clear();

        static::getContainer()->get(HelpCallService::class)->trigger($shift, $caller, 1);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic($this->departmentTopic()));
    }

    public function testCancellingACallTellsTheDepartment(): void
    {
        $shift = $this->scenario->shift('Gate', '+20 minutes', '+3 hours');
        $caller = $this->scenario->user(['call:trigger']);
        $calls = static::getContainer()->get(HelpCallService::class);
        $call = $calls->trigger($shift, $caller, 1);
        $this->flush();
        RecordedUpdates::clear();

        $calls->cancel($call);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic($this->departmentTopic()));
    }

    /**
     * A holder of board:view alone must be able to subscribe to the department, or the board renders
     * once and never updates again - which looks exactly like a board with nothing happening on it.
     */
    public function testBoardAccessAloneCarriesTheDepartmentTopic(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'board:view']) ?? new Privilege('board:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $this->em->persist($user->assignGroup($group, $this->scenario->department));
        $this->em->persist($user);
        $this->em->flush();

        $topics = static::getContainer()->get(\App\Mercure\TopicBuilder::class)->forUser($user);

        self::assertContains($this->departmentTopic(), $topics);
    }

    /** The board reads its thresholds through the settings service, which must be reachable. */
    public function testTheBoardSettingsServiceIsWired(): void
    {
        self::assertGreaterThan(0, static::getContainer()->get(BoardSettings::class)->preStartWarnMinutes());
    }
}
