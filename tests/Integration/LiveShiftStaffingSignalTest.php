<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\NeededVolunteerType;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Mercure\TopicBuilder;
use App\Mercure\Topics;
use App\Mercure\UpdatePublisher;
use App\Service\Assignment\ManualAssignmentService;
use App\Service\ShiftSignupService;
use App\Tests\DatabaseTestCase;
use App\Tests\Support\RecordedUpdates;

/**
 * Staffing changes, and who hears about them.
 *
 * Capacity is the one thing on the apply and staffing screens that changes because of what someone
 * else did, and it decides whether a row can still be applied to. It is addressed to the shift's
 * department, so a viewer of another department is never woken - and it is a signal, because every
 * row is rendered per viewer: their eligibility, their overlaps, their hours, and on the staffing
 * page the names of the people assigned.
 */
final class LiveShiftStaffingSignalTest extends DatabaseTestCase
{
    private Department $alpha;
    private Department $bravo;
    private VolunteerType $type;
    private Shift $shift;
    private Group $staffGroup;

    private function signup(): ShiftSignupService
    {
        return static::getContainer()->get(ShiftSignupService::class);
    }

    private function assignments(): ManualAssignmentService
    {
        return static::getContainer()->get(ManualAssignmentService::class);
    }

    private function topics(): TopicBuilder
    {
        return static::getContainer()->get(TopicBuilder::class);
    }

    private function flush(): void
    {
        static::getContainer()->get(UpdatePublisher::class)->flush();
    }

    protected function setUp(): void
    {
        parent::setUp();
        RecordedUpdates::clear();

        $this->alpha = new Department('Alpha', 'alpha-'.bin2hex(random_bytes(3)));
        $this->bravo = new Department('Bravo', 'bravo-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->alpha);
        $this->em->persist($this->bravo);

        $this->type = new VolunteerType('Crew '.bin2hex(random_bytes(3)));
        $this->em->persist($this->type);

        $this->staffGroup = new Group('Staff', 'staff-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        $privilege = new Privilege('shift:view');
        $this->em->persist($privilege);
        $this->staffGroup->addPrivilege($privilege);
        $this->em->persist($this->staffGroup);

        $this->shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable('+2 days 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+2 days 12:00'))
            ->setDepartment($this->alpha)
            ->setAudience(ShiftAudience::ALL_STAFF)
            ->setState(ShiftState::PUBLISHED);
        $this->em->persist($this->shift);
        $need = new NeededVolunteerType($this->type, 2);
        $this->shift->addNeededVolunteerType($need);
        $this->em->persist($need);
        $this->em->flush();
    }

    private function member(string $name, ?Department $department = null): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.bin2hex(random_bytes(2)).'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        if ($department === null) {
            $user->addGroup($this->staffGroup);
        } else {
            $user->assignGroup($this->staffGroup, $department);
        }
        $this->em->persist($user);

        $membership = new UserVolunteerType($user, $this->type);
        $membership->setConfirmedBy($user);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    public function testSigningUpWakesTheShiftsDepartment(): void
    {
        $user = $this->member('applicant');

        $this->signup()->signUp($user, $this->shift, $this->type);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::departmentShifts($this->alpha)));
        self::assertCount(
            0,
            RecordedUpdates::forTopic(Topics::departmentShifts($this->bravo)),
            'another department has no interest in this shift and must not be woken',
        );
        self::assertNotEmpty(
            RecordedUpdates::forTopic(Topics::userStatus($user)),
            'their operational status is derived from their assignments',
        );
    }

    public function testWithdrawingWakesTheShiftsDepartment(): void
    {
        $user = $this->member('applicant');
        $entry = $this->signup()->signUp($user, $this->shift, $this->type);
        $this->flush();
        RecordedUpdates::clear();

        $this->signup()->cancel($entry);
        $this->flush();

        self::assertCount(1, RecordedUpdates::forTopic(Topics::departmentShifts($this->alpha)));
    }

    /** A manager assigning someone must reach the same screens as the volunteer applying. */
    public function testManagerAssignmentAndRemovalWakeTheDepartment(): void
    {
        $user = $this->member('assignee');

        $entry = $this->assignments()->assign($this->shift, $user, $this->type);
        $this->flush();
        self::assertCount(1, RecordedUpdates::forTopic(Topics::departmentShifts($this->alpha)));

        RecordedUpdates::clear();
        $this->assignments()->remove($entry);
        $this->flush();
        self::assertCount(1, RecordedUpdates::forTopic(Topics::departmentShifts($this->alpha)));
    }

    /** The hub learns that a department's staffing moved, never who or for what. */
    public function testTheSignalNamesNobody(): void
    {
        $user = $this->member('applicant');

        $this->signup()->signUp($user, $this->shift, $this->type);
        $this->flush();

        $data = RecordedUpdates::forTopic(Topics::departmentShifts($this->alpha))[0]->getData();

        self::assertStringNotContainsString('applicant', $data);
        self::assertStringNotContainsString('Gate', $data);
        self::assertStringNotContainsString((string) $user->getUuid(), $data);
    }

    /**
     * A member of one department must not be handed another's topic.
     *
     * The exception is deliberate: staff see other departments' all-staff shifts on the apply
     * screen, so those departments' capacity changes have to reach them or those rows would never
     * update. It is scoped to departments actually running such a shift, not to every department.
     */
    public function testDepartmentTopicsFollowWhatTheUserCanActuallySee(): void
    {
        $alphaOnly = $this->member('alphastaff', $this->alpha);

        $topics = $this->topics()->forUser($alphaOnly);

        self::assertContains(
            Topics::departmentShifts($this->alpha),
            $topics,
            'their own department, and it runs an all-staff shift they can apply to',
        );

        // Bravo runs nothing this user can see, so it stays out of their token.
        self::assertNotContains(Topics::departmentShifts($this->bravo), $topics);
    }

    /** A volunteer with no staff role sees no department topics at all. */
    public function testAVolunteerGetsNoDepartmentTopics(): void
    {
        $group = new Group('Volunteer', 'volunteer-'.bin2hex(random_bytes(2)), null);
        $this->em->persist($group);
        $volunteer = new User();
        $volunteer->setName('volunteer')->setEmail('volunteer@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $volunteer->addGroup($group);
        $this->em->persist($volunteer);
        $this->em->flush();

        $topics = $this->topics()->forUser($volunteer);

        self::assertNotContains(Topics::departmentShifts($this->alpha), $topics);
        self::assertNotContains(Topics::departmentShifts($this->bravo), $topics);
    }
}
