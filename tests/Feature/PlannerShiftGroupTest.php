<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftGroup;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Assigning a planner batch selection to a shift group, and creating a group from that selection.
 *
 * The rule that matters most here: a group and its shifts share one department. `ids[]` is user
 * input and the planner admits any shift the manager may manage, so without an explicit check the
 * planner would be the one way to build a cross-department group - and every department-scoped
 * permission check against that group afterwards would have no authoritative department to use.
 */
final class PlannerShiftGroupTest extends DatabaseWebTestCase
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

    /** A manager holding shift:manage on every department passed in. */
    private function manager(Department ...$departments): User
    {
        $suffix = bin2hex(random_bytes(4));
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'shift:manage']) ?? new Privilege('shift:manage');
        $this->em->persist($privilege);

        $group = new Group('Mgr '.$suffix, 'mgr-'.$suffix, 'ROLE_USER');
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('mgr-'.$suffix)->setEmail('mgr-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->completeOnboarding();
        foreach ($departments as $department) {
            $this->em->persist($user->assignGroup($group, $department));
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function otherDepartment(): Department
    {
        $department = new Department('Other '.bin2hex(random_bytes(3)), 'other-'.bin2hex(random_bytes(3)));
        $this->em->persist($department);
        $this->em->flush();

        return $department;
    }

    private function shiftIn(Department $department, string $title): Shift
    {
        $shift = (new Shift())->setTitle($title)
            ->setStartsAt(new \DateTimeImmutable('2036-06-01 10:00'))
            ->setEndsAt(new \DateTimeImmutable('2036-06-01 12:00'))
            ->setDepartment($department)
            ->setState(ShiftState::DRAFT);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    private function group(Department $department, string $name = 'Main Show'): ShiftGroup
    {
        $group = new ShiftGroup($department, $name);
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, $body);
    }

    /**
     * The planner_edit token as the browser would have it.
     *
     * Minted by rendering the planner itself: a CSRF token is stored in the session, and building one
     * outside a request has no session to build it in.
     */
    private function token(?Department $department = null): string
    {
        $department ??= $this->scenario->department;
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$department->getUuid());

        return $crawler->filter('.planner-panel input[name="_token"]')->first()->attr('value');
    }

    public function testBatchAssignsEverySelectedShiftToTheGroup(): void
    {
        $a = $this->scenario->shift('Rehearsal', 'tomorrow 10:00');
        $b = $this->scenario->shift('Main event', '+2 days 09:00');
        $group = $this->group($this->scenario->department);

        $this->client->loginUser($this->manager($this->scenario->department));
        $this->post('/manage-shifts/planner/batch', [
            '_token' => $this->token(),
            'ids' => [(string) $a->getUuid(), (string) $b->getUuid()],
            'shift_group' => (string) $group->getUuid(),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        foreach ([$a, $b] as $shift) {
            $reloaded = $this->em->getRepository(Shift::class)->find($shift->getId());
            self::assertNotNull($reloaded->getShiftGroup());
            self::assertSame($group->getId(), $reloaded->getShiftGroup()->getId());
        }
    }

    public function testBatchCanRemoveShiftsFromTheirGroup(): void
    {
        $shift = $this->scenario->shift('Rehearsal', 'tomorrow 10:00');
        $group = $this->group($this->scenario->department);
        $group->addShift($shift);
        $this->em->flush();

        $this->client->loginUser($this->manager($this->scenario->department));
        $this->post('/manage-shifts/planner/batch', [
            '_token' => $this->token(),
            'ids' => [(string) $shift->getUuid()],
            'shift_group' => 'none',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->getRepository(Shift::class)->find($shift->getId())->getShiftGroup());
    }

    public function testAnEmptyChoiceLeavesTheGroupAlone(): void
    {
        $shift = $this->scenario->shift('Rehearsal', 'tomorrow 10:00');
        $group = $this->group($this->scenario->department);
        $group->addShift($shift);
        $this->em->flush();

        $this->client->loginUser($this->manager($this->scenario->department));
        $this->post('/manage-shifts/planner/batch', [
            '_token' => $this->token(),
            'ids' => [(string) $shift->getUuid()],
            'shift_group' => '',
            'duration_minutes' => '60',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Shift::class)->find($shift->getId())->getShiftGroup());
    }

    /**
     * A group holds one department's shifts, whatever ids[] claims. The manager legitimately holds
     * both departments, so the refusal is the group rule itself and not a permission check standing
     * in for it.
     */
    public function testAShiftFromAnotherDepartmentIsRefusedAndNothingIsChanged(): void
    {
        $mine = $this->scenario->shift('Rehearsal', 'tomorrow 10:00');
        $elsewhere = $this->otherDepartment();
        $foreign = $this->shiftIn($elsewhere, 'Foreign shift');
        $group = $this->group($this->scenario->department);

        $this->client->loginUser($this->manager($this->scenario->department, $elsewhere));
        $this->post('/manage-shifts/planner/batch', [
            '_token' => $this->token(),
            'ids' => [(string) $mine->getUuid(), (string) $foreign->getUuid()],
            'shift_group' => (string) $group->getUuid(),
        ]);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Shift::class)->find($foreign->getId())->getShiftGroup());
        self::assertNull(
            $this->em->getRepository(Shift::class)->find($mine->getId())->getShiftGroup(),
            'The whole batch is refused, so the shift that would have been valid is untouched too.',
        );
    }

    public function testAGroupFromAnotherDepartmentIsRefused(): void
    {
        $shift = $this->scenario->shift('Rehearsal', 'tomorrow 10:00');
        $elsewhere = $this->otherDepartment();
        $foreignGroup = $this->group($elsewhere, 'Their group');

        $this->client->loginUser($this->manager($this->scenario->department, $elsewhere));
        $this->post('/manage-shifts/planner/batch', [
            '_token' => $this->token(),
            'ids' => [(string) $shift->getUuid()],
            'shift_group' => (string) $foreignGroup->getUuid(),
        ]);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Shift::class)->find($shift->getId())->getShiftGroup());
    }

    /**
     * Grouping shifts that already carry sign-ups leaves volunteers on part of a commitment, which is
     * the same question `/manage/shift-groups` asks. The planner must not be a way around it, and
     * answering yes replays the same request carrying the confirm flag.
     */
    public function testGroupingShiftsWithSignUpsAsksForConfirmationFirst(): void
    {
        $withEntries = $this->scenario->shift('Main event', 'tomorrow 10:00');
        $empty = $this->scenario->shift('Rehearsal', '+2 days 09:00');
        $volunteer = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->scenario->signUp($volunteer, $withEntries);
        $group = $this->group($this->scenario->department);

        $this->client->loginUser($this->manager($this->scenario->department));
        $token = $this->token();
        $this->post('/manage-shifts/planner/batch', [
            '_token' => $token,
            'ids' => [(string) $withEntries->getUuid(), (string) $empty->getUuid()],
            'shift_group' => (string) $group->getUuid(),
        ]);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
        self::assertArrayHasKey('confirm', $data);

        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(Shift::class)->find($empty->getId())->getShiftGroup(),
            'Nothing is written until the manager answers.',
        );

        $this->post('/manage-shifts/planner/batch', [
            '_token' => $token,
            'ids' => [(string) $withEntries->getUuid(), (string) $empty->getUuid()],
            'shift_group' => (string) $group->getUuid(),
            'confirm' => '1',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Shift::class)->find($empty->getId())->getShiftGroup());
    }

    public function testCreatingAGroupFromTheSelectionAssignsItStraightAway(): void
    {
        $a = $this->scenario->shift('Rehearsal', 'tomorrow 10:00');
        $b = $this->scenario->shift('Main event', '+2 days 09:00');

        $this->client->loginUser($this->manager($this->scenario->department));
        $this->post('/manage-shifts/planner/shift-group', [
            '_token' => $this->token(),
            'department' => (string) $this->scenario->department->getUuid(),
            'name' => 'Main Show 2036',
            'ids' => [(string) $a->getUuid(), (string) $b->getUuid()],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame(2, $data['assigned']);

        $this->em->clear();
        $created = $this->em->getRepository(ShiftGroup::class)->findOneBy(['name' => 'Main Show 2036']);
        self::assertNotNull($created);
        self::assertCount(2, $created->getShifts());
    }

    public function testCreatingAGroupRefusesABlankOrDuplicateName(): void
    {
        $this->group($this->scenario->department, 'Taken');
        $this->client->loginUser($this->manager($this->scenario->department));
        $token = $this->token();

        $this->post('/manage-shifts/planner/shift-group', [
            '_token' => $token,
            'department' => (string) $this->scenario->department->getUuid(),
            'name' => '  ',
        ]);
        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['ok']);

        $this->post('/manage-shifts/planner/shift-group', [
            '_token' => $token,
            'department' => (string) $this->scenario->department->getUuid(),
            'name' => 'Taken',
        ]);
        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['ok']);
    }

    public function testCreatingAGroupRefusesAShiftFromAnotherDepartment(): void
    {
        $elsewhere = $this->otherDepartment();
        $foreign = $this->shiftIn($elsewhere, 'Foreign shift');

        $this->client->loginUser($this->manager($this->scenario->department, $elsewhere));
        $this->post('/manage-shifts/planner/shift-group', [
            '_token' => $this->token(),
            'department' => (string) $this->scenario->department->getUuid(),
            'name' => 'Cross department',
            'ids' => [(string) $foreign->getUuid()],
        ]);

        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['ok']);
        self::assertNull(
            $this->em->getRepository(ShiftGroup::class)->findOneBy(['name' => 'Cross department']),
            'A refused create must not leave the group behind.',
        );
    }

    public function testCreatingAGroupIsRefusedForADepartmentTheManagerDoesNotHold(): void
    {
        $elsewhere = $this->otherDepartment();

        $this->client->loginUser($this->manager($this->scenario->department));
        $this->post('/manage-shifts/planner/shift-group', [
            '_token' => $this->token(),
            'department' => (string) $elsewhere->getUuid(),
            'name' => 'Not mine',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The batch form's number inputs post an empty string when left blank, which is the normal case
     * when a manager only wants to set the group. They must be read with a cast, not getInt(),
     * which refuses an empty string with a 400. The payload below is exactly what the browser sends
     * for untouched fields: an empty number input, and the placeholder option of each picker.
     */
    public function testBlankNumberFieldsDoNotRefuseTheBatch(): void
    {
        $shift = $this->scenario->shift('Rehearsal', 'tomorrow 10:00');
        $group = $this->group($this->scenario->department);

        $this->client->loginUser($this->manager($this->scenario->department));
        $this->post('/manage-shifts/planner/batch', [
            '_token' => $this->token(),
            'ids' => [(string) $shift->getUuid()],
            'shift_group' => (string) $group->getUuid(),
            'duration_minutes' => '',
            'needed_count' => '',
            'needed_type' => '',
            'task' => '',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Shift::class)->find($shift->getId())->getShiftGroup());
    }
}
