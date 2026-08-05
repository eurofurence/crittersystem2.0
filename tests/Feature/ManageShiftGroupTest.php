<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftGroup;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Managing shift groups.
 *
 * `shift:manage` is department-scoped and PrivilegeVoter fails open when it is handed no subject, so
 * the point of these tests is that a manager delegated to one department cannot reach, edit or
 * rewire another department's groups.
 */
final class ManageShiftGroupTest extends DatabaseWebTestCase
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

    /** A manager holding shift:manage scoped to one department only. */
    private function scopedManager(Department $department): User
    {
        $suffix = bin2hex(random_bytes(4));
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'shift:manage']) ?? new Privilege('shift:manage');
        $this->em->persist($privilege);

        $group = new Group('Mgr '.$suffix, 'mgr-'.$suffix, 'ROLE_USER');
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $user = new User();
        $user->setName('mgr-'.$suffix)->setEmail('mgr-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword('x');
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $department));
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

    private function group(Department $department, string $name = 'Main Show'): ShiftGroup
    {
        $group = new ShiftGroup($department, $name);
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }


    /** The picker's CSRF token, as the browser would read it off the edit page. */
    private function pickerToken(ShiftGroup $group): string
    {
        $crawler = $this->client->request('GET', '/manage/shift-groups/'.$group->getUuid().'/edit');
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-shift-group-picker-token-value]')->attr('data-shift-group-picker-token-value');
    }

    /**
     * Post shifts to the picker's add endpoint.
     *
     * @param string[] $uuids
     *
     * @return array<string, mixed> the decoded JSON reply
     */
    private function addShifts(ShiftGroup $group, array $uuids, string $token, bool $confirm = false): array
    {
        $body = ['_token' => $token, 'shifts' => $uuids];
        if ($confirm) {
            $body['confirm'] = '1';
        }
        $this->client->request('POST', '/manage/shift-groups/'.$group->getUuid().'/members', $body);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testAManagerCanCreateAGroupAndAddAShiftToIt(): void
    {
        $shift = $this->scenario->shift('Main event', 'tomorrow 10:00');

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        $crawler = $this->client->request('GET', '/manage/shift-groups/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="shift_group"]')->form();
        $form['shift_group[name]'] = 'Main Show';
        $form['shift_group[department]'] = (string) $this->scenario->department->getId();
        $this->client->submit($form);

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        $group = $this->em->getRepository(ShiftGroup::class)->findOneBy(['name' => 'Main Show']);
        self::assertNotNull($group);

        $this->addShifts($group, [(string) $shift->getUuid()], $this->pickerToken($group));

        $this->em->clear();
        $reloaded = $this->em->getRepository(Shift::class)->find($shift->getId());
        self::assertNotNull($reloaded->getShiftGroup());
        self::assertSame('Main Show', $reloaded->getShiftGroup()->getName());
    }

    public function testTheDepartmentCannotBeChangedOnceTheGroupHasShifts(): void
    {
        $group = $this->group($this->scenario->department);
        $group->addShift($this->scenario->shift('Main event', 'tomorrow 10:00'));
        $this->em->flush();

        $elsewhere = $this->otherDepartment();
        $manager = $this->scopedManager($this->scenario->department);
        // Also grants the manager the other department, so the refusal is the group rule and not a
        // permission check standing in for it.
        $this->em->persist($manager->assignGroup($manager->getGroupAssignments()->first()->getGroup(), $elsewhere));
        $this->em->flush();

        $this->client->loginUser($manager);
        $crawler = $this->client->request('GET', '/manage/shift-groups/'.$group->getUuid().'/edit');

        $form = $crawler->filter('form[name="shift_group"]')->form();
        $form['shift_group[department]'] = (string) $elsewhere->getId();
        $this->client->submit($form);
        $this->client->followRedirect();

        $this->em->clear();
        $reloaded = $this->em->getRepository(ShiftGroup::class)->find($group->getId());
        self::assertSame(
            $this->scenario->department->getId(),
            $reloaded->getDepartment()->getId(),
            'Moving a populated group would strand its shifts in another department\'s scope.',
        );
    }





    public function testTheListOnlyShowsGroupsTheManagerMayManage(): void
    {
        $mine = $this->group($this->scenario->department, 'Mine');
        $theirs = $this->group($this->otherDepartment(), 'Theirs');

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        $this->client->request('GET', '/manage/shift-groups');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($mine->getName(), $html);
        self::assertStringNotContainsString($theirs->getName(), $html);
    }

    public function testEditingAnotherDepartmentsGroupIsRefused(): void
    {
        $theirs = $this->group($this->otherDepartment());

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        $this->client->request('GET', '/manage/shift-groups/'.$theirs->getUuid().'/edit');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAShiftFromAnotherDepartmentCannotJoinTheGroup(): void
    {
        $group = $this->group($this->scenario->department);
        $foreign = (new Shift())->setTitle('Foreign shift')
            ->setStartsAt(new \DateTimeImmutable('tomorrow 10:00'))
            ->setEndsAt(new \DateTimeImmutable('tomorrow 12:00'))
            ->setDepartment($this->otherDepartment());
        $this->em->persist($foreign);
        $this->em->flush();

        // A candidate in the group's own department, so the add form (and its CSRF token) renders.
        $this->scenario->shift('Own shift', 'tomorrow 10:00');

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        $reply = $this->addShifts($group, [(string) $foreign->getUuid()], $this->pickerToken($group));
        self::assertFalse($reply['ok']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Shift::class)->find($foreign->getId());
        self::assertNull($reloaded->getShiftGroup(), 'A group holds shifts of its own department only.');
    }

    /**
     * Deleting a group must not delete the work it labelled: the shifts survive, ungrouped, and the
     * sign-ups volunteers hold on them stay.
     */
    public function testDeletingAGroupKeepsItsShiftsAndSignUps(): void
    {
        $group = $this->group($this->scenario->department);
        $shift = $this->scenario->shift('Main event', 'tomorrow 10:00');
        $group->addShift($shift);
        $this->em->flush();

        $volunteer = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->scenario->signUp($volunteer, $shift);

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        $crawler = $this->client->request('GET', '/manage/shift-groups');
        $this->client->submit($crawler->filter('form[action*="/delete"]')->form());

        $this->em->clear();
        $reloaded = $this->em->getRepository(Shift::class)->find($shift->getId());
        self::assertNotNull($reloaded, 'Deleting a label must never delete the shifts it labelled.');
        self::assertNull($reloaded->getShiftGroup());
        self::assertSame(1, $this->em->getRepository(\App\Entity\ShiftEntry::class)->count(['shift' => $shift->getId()]));
    }

    public function testAddingAMemberAsksBeforeLeavingVolunteersOnAPartialCommitment(): void
    {
        $group = $this->group($this->scenario->department);
        $existing = $this->scenario->shift('Main event', 'tomorrow 10:00');
        $group->addShift($existing);
        $this->em->flush();

        $volunteer = $this->scenario->user(['shift:view', 'shift:self'], $this->scenario->type);
        $this->scenario->signUp($volunteer, $existing);

        $newcomer = $this->scenario->shift('Show rehearsal', '+2 days 10:00');

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        $token = $this->pickerToken($group);

        // Asked before anything is written, the same way the planner asks.
        $reply = $this->addShifts($group, [(string) $newcomer->getUuid()], $token);
        self::assertFalse($reply['ok']);
        self::assertArrayHasKey('confirm', $reply);

        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(Shift::class)->find($newcomer->getId())->getShiftGroup(),
            'Nothing is written until the manager answers.',
        );

        $reply = $this->addShifts($group, [(string) $newcomer->getUuid()], $token, confirm: true);
        self::assertTrue($reply['ok']);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Shift::class)->find($newcomer->getId())->getShiftGroup());
        // No back-filling: the volunteer is not silently put on a shift they never applied to.
        self::assertSame(0, $this->em->getRepository(\App\Entity\ShiftEntry::class)->count(['shift' => $newcomer->getId()]));
    }

    // --- the member picker ---------------------------------------------

    /** @param array<string, string> $query */
    private function candidates(ShiftGroup $group, array $query = []): string
    {
        $this->client->request('GET', '/manage/shift-groups/'.$group->getUuid().'/candidates?'.http_build_query($query));
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * The picker fragments must not answer with a whole document: the controller injects the reply
     * straight into the page, so a layout would end up nested inside the card.
     */
    public function testThePickerFragmentsAreNotFullPages(): void
    {
        $group = $this->group($this->scenario->department);
        $this->scenario->shift('Main event', 'tomorrow 10:00');
        $this->client->loginUser($this->scopedManager($this->scenario->department));

        self::assertStringNotContainsString('<html', $this->candidates($group));

        $this->client->request('GET', '/manage/shift-groups/'.$group->getUuid().'/members-list');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
    }

    public function testPastShiftsAreHiddenUntilAskedFor(): void
    {
        $group = $this->group($this->scenario->department);
        $this->scenario->shift('Yesterday shift', '-2 days 10:00');
        $this->scenario->shift('Future shift', 'tomorrow 10:00');
        $this->client->loginUser($this->scopedManager($this->scenario->department));

        $html = $this->candidates($group);
        self::assertStringContainsString('Future shift', $html);
        self::assertStringNotContainsString('Yesterday shift', $html);

        $withPast = $this->candidates($group, ['past' => '1']);
        self::assertStringContainsString('Yesterday shift', $withPast);
    }

    public function testTheSearchBoxNarrowsByTitle(): void
    {
        $group = $this->group($this->scenario->department);
        $this->scenario->shift('Stage rehearsal', 'tomorrow 10:00');
        $this->scenario->shift('Front desk', 'tomorrow 12:00');
        $this->client->loginUser($this->scopedManager($this->scenario->department));

        $html = $this->candidates($group, ['q' => 'rehear']);
        self::assertStringContainsString('Stage rehearsal', $html);
        self::assertStringNotContainsString('Front desk', $html);
    }

    public function testTheDayFilterNarrowsTheCandidates(): void
    {
        $group = $this->group($this->scenario->department);
        $today = new \DateTimeImmutable('tomorrow 10:00');
        $this->scenario->shift('Tomorrow shift', 'tomorrow 10:00');
        $this->scenario->shift('Later shift', '+3 days 10:00');
        $this->client->loginUser($this->scopedManager($this->scenario->department));

        $html = $this->candidates($group, ['day' => $today->format('Y-m-d')]);
        self::assertStringContainsString('Tomorrow shift', $html);
        self::assertStringNotContainsString('Later shift', $html);
    }

    /**
     * A shift belonging to another group is offered but disabled and names its owner, so a manager
     * can tell "taken" from "does not exist". Posting it anyway is still refused.
     */
    public function testAShiftInAnotherGroupIsShownDisabledAndCannotBeAdded(): void
    {
        $group = $this->group($this->scenario->department, 'Target');
        $other = $this->group($this->scenario->department, 'Owner');
        $taken = $this->scenario->shift('Taken shift', 'tomorrow 10:00');
        $other->addShift($taken);
        $this->em->flush();

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        $html = $this->candidates($group);
        self::assertStringContainsString('Taken shift', $html);
        self::assertStringContainsString('Owner', $html);
        self::assertStringContainsString('disabled', $html);

        $reply = $this->addShifts($group, [(string) $taken->getUuid()], $this->pickerToken($group));
        self::assertFalse($reply['ok']);

        $this->em->clear();
        self::assertSame(
            $other->getId(),
            $this->em->getRepository(Shift::class)->find($taken->getId())->getShiftGroup()->getId(),
        );
    }

    /** The group's own members belong in the list above, not in the picker. */
    public function testTheGroupsOwnShiftsAreNotOfferedAsCandidates(): void
    {
        $group = $this->group($this->scenario->department);
        $member = $this->scenario->shift('Already a member', 'tomorrow 10:00');
        $group->addShift($member);
        $this->em->flush();

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        self::assertStringNotContainsString('Already a member', $this->candidates($group));
    }

    public function testSeveralShiftsAreAddedInOneAction(): void
    {
        $group = $this->group($this->scenario->department);
        $a = $this->scenario->shift('Rehearsal', 'tomorrow 10:00');
        $b = $this->scenario->shift('Main event', '+2 days 10:00');
        $c = $this->scenario->shift('Teardown', '+3 days 10:00');

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        $reply = $this->addShifts(
            $group,
            [(string) $a->getUuid(), (string) $b->getUuid(), (string) $c->getUuid()],
            $this->pickerToken($group),
        );

        self::assertTrue($reply['ok']);
        self::assertSame(3, $reply['added']);

        $this->em->clear();
        self::assertCount(3, $this->em->getRepository(ShiftGroup::class)->find($group->getId())->getShifts());
    }

    /** One bad id refuses the batch; applying half of a selection would leave the manager guessing. */
    public function testOneRefusedShiftRefusesTheWholeBatch(): void
    {
        $group = $this->group($this->scenario->department);
        $good = $this->scenario->shift('Rehearsal', 'tomorrow 10:00');
        $foreign = (new Shift())->setTitle('Foreign shift')
            ->setStartsAt(new \DateTimeImmutable('tomorrow 10:00'))
            ->setEndsAt(new \DateTimeImmutable('tomorrow 12:00'))
            ->setDepartment($this->otherDepartment());
        $this->em->persist($foreign);
        $this->em->flush();

        $this->client->loginUser($this->scopedManager($this->scenario->department));
        $reply = $this->addShifts(
            $group,
            [(string) $good->getUuid(), (string) $foreign->getUuid()],
            $this->pickerToken($group),
        );

        self::assertFalse($reply['ok']);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Shift::class)->find($good->getId())->getShiftGroup());
    }

    public function testThePickerIsRefusedForAnotherDepartmentsGroup(): void
    {
        $theirs = $this->group($this->otherDepartment());
        $this->client->loginUser($this->scopedManager($this->scenario->department));

        $this->client->request('GET', '/manage/shift-groups/'.$theirs->getUuid().'/candidates');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * "Any day / any audience / any shift type" reach the server as empty strings, and an unset
     * filter is not a malformed request. Reading them with getInt()/getBoolean() answered 400.
     */
    public function testEmptyFilterValuesAreTreatedAsUnset(): void
    {
        $group = $this->group($this->scenario->department);
        $this->scenario->shift('Main event', 'tomorrow 10:00');
        $this->client->loginUser($this->scopedManager($this->scenario->department));

        $html = $this->candidates($group, ['day' => '', 'audience' => '', 'type' => '', 'q' => '', 'past' => '']);

        self::assertStringContainsString('Main event', $html);
    }
}
