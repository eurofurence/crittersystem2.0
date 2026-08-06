<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\UserGroupAssignment;
use App\Entity\VolunteerType;
use App\Enum\ShiftState;
use App\Repository\ShiftRepository;
use App\Repository\VolunteerTypeRepository;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The planner rules a manager relies on when planning a department:
 * the department has to be confirmed before anything loads, a shift cannot be saved without a shift
 * task, the Critter-type pickers offer the whole vocabulary with this department's own types first,
 * and the batch tools (task, delete) plus discarding drafts act on exactly the shifts they name.
 */
final class PlannerTask17Test extends DatabaseWebTestCase
{
    private Department $alpha;
    private Department $bravo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = new Department('Alpha', 'alpha-'.bin2hex(random_bytes(2)));
        $this->bravo = new Department('Bravo', 'bravo-'.bin2hex(random_bytes(2)));
        $this->em->persist($this->alpha);
        $this->em->persist($this->bravo);
        $this->em->flush();
    }

    /** @param string[] $privileges */
    private function login(array $privileges = ['manageshifts:view', 'shift:manage', 'shift:publish']): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Grp '.$suffix, 'grp-'.$suffix, 'ROLE_STAFF');
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('mgr-'.$suffix)->setEmail('mgr-'.$suffix.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->persist(new UserGroupAssignment($user, $group, null));
        $this->em->flush();

        // The group assignment is its own entity, so the already-hydrated User still has an empty
        // assignments collection until the identity map is dropped - and would carry no privileges.
        $email = $user->getEmail();
        $this->em->clear();
        $this->reloadDepartments();

        $fresh = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->client->loginUser($fresh);

        return $fresh;
    }

    private function reloadDepartments(): void
    {
        $repo = $this->em->getRepository(Department::class);
        $this->alpha = $repo->findOneBy(['slug' => $this->alpha->getSlug()]);
        $this->bravo = $repo->findOneBy(['slug' => $this->bravo->getSlug()]);
    }

    /** A manager whose shift:manage only covers one department. */
    private function loginScopedTo(Department $scope): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Scoped '.$suffix, 'scoped-'.$suffix, 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:manage'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('scoped-'.$suffix)->setEmail('scoped-'.$suffix.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->persist(new UserGroupAssignment($user, $group, $scope));
        $this->em->flush();

        // The group assignment is its own entity, so the already-hydrated User still has an empty
        // assignments collection until the identity map is dropped - and would carry no privileges.
        $email = $user->getEmail();
        $this->em->clear();
        $this->reloadDepartments();

        $fresh = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        $this->client->loginUser($fresh);

        return $fresh;
    }

    private function task(string $name, ?Department $department): ShiftTask
    {
        $task = new ShiftTask($name);
        $task->setDepartment($department);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function shift(Department $dept, string $start, string $end, ShiftState $state = ShiftState::DRAFT): Shift
    {
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($dept)
            ->setState($state);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    private function editToken(Department $dept): string
    {
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());

        return $crawler->filter('.planner')->attr('data-planner-edit-token-value');
    }

    // ---- department confirmation -------------------------------------------

    /**
     * Publishing, discarding drafts and mass delete all act on whichever department is loaded, so
     * the planner must not pick one on the manager's behalf: with no department named it asks.
     */
    public function testPlannerAsksForADepartmentBeforeRenderingTheGrid(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/planner');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.planner-grid'), 'no grid until a department is confirmed');
        self::assertCount(0, $crawler->filter('#planner-publish-bar'), 'nothing publishable is offered either');
        self::assertCount(1, $crawler->filter('#planner-department'));
    }

    public function testConfirmingADepartmentLoadsTheGrid(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->alpha->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.planner-grid'));
    }

    // ---- shift task is required --------------------------------------------

    public function testCreatingAShiftWithoutATaskIsRejected(): void
    {
        $this->login();

        $this->client->request('POST', '/manage-shifts/planner/create', [
            '_token' => $this->editToken($this->alpha),
            'department' => $this->alpha->getUuid(),
            'start' => '2026-06-01T10:00',
            'end' => '2026-06-01T12:00',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, static::getContainer()->get(ShiftRepository::class)->findAll());
    }

    public function testPaintingWithoutATaskIsRejected(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->alpha->getUuid());
        $planner = $crawler->filter('.planner');

        $this->client->request('POST', '/manage-shifts/planner/paint', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            '_token' => $planner->attr('data-planner-paint-token-value'),
            'department' => $this->alpha->getUuid(),
            'intervals' => [['start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T12:00:00']],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, static::getContainer()->get(ShiftRepository::class)->findAll());
    }

    /** Another department's task is as invalid as no task: the picker never offered it. */
    public function testCreatingAShiftWithAnotherDepartmentsTaskIsRejected(): void
    {
        $this->login();
        $foreign = $this->task('Bravo only', $this->bravo);

        $this->client->request('POST', '/manage-shifts/planner/create', [
            '_token' => $this->editToken($this->alpha),
            'department' => $this->alpha->getUuid(),
            'start' => '2026-06-01T10:00',
            'end' => '2026-06-01T12:00',
            'task' => $foreign->getUuid(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, static::getContainer()->get(ShiftRepository::class)->findAll());
    }

    /** The picker must not offer what the server will refuse, nor an empty "no task" choice. */
    public function testTheAddShiftPickerIsRequiredAndOffersNoEmptyTask(): void
    {
        $this->login();
        $this->task('Briefing', $this->alpha);

        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->alpha->getUuid());

        $select = $crawler->filter('#add-task');
        self::assertNotNull($select->attr('required'), 'the picker is a required field');
        $values = $select->filter('option')->each(static fn ($o) => $o->attr('value'));
        self::assertNotContains('0', $values, 'there is no "None" option left to pick');
    }

    // ---- volunteer types are shared, this department's come first ----------

    /**
     * The whole vocabulary is offered everywhere. Hiding a type that another department had claimed
     * left a planner with only the two unlinked base types once departments started claiming theirs,
     * with no way to plan for a role the staffing screen would still happily assign.
     */
    public function testTypesClaimedByAnotherDepartmentAreStillOfferedAndHonoured(): void
    {
        $this->login();
        $this->task('Briefing', $this->alpha);

        $shared = new VolunteerType('Runner');
        $foreign = new VolunteerType('Bravo Audio');
        $this->em->persist($shared);
        $this->em->persist($foreign);
        $this->bravo->addVolunteerType($foreign);
        $this->em->flush();
        // Department owns the association, so the type's own side is only correct once reloaded.
        $sharedUuid = (string) $shared->getUuid();
        $foreignUuid = (string) $foreign->getUuid();
        $this->em->clear();
        $this->reloadDepartments();

        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->alpha->getUuid());
        $names = $crawler->filter('#planner-add-modal input[type="number"]')->each(static fn ($n) => $n->attr('name'));
        self::assertContains('needed['.$sharedUuid.']', $names, 'an unclaimed type is offered');
        self::assertContains('needed['.$foreignUuid.']', $names, "Bravo's own type is offered to Alpha too");

        // ...and posting it attaches it.
        $this->client->request('POST', '/manage-shifts/planner/create', [
            '_token' => $this->editToken($this->alpha),
            'department' => $this->alpha->getUuid(),
            'start' => '2026-06-01T10:00',
            'end' => '2026-06-01T12:00',
            'task' => $this->em->getRepository(ShiftTask::class)->findOneBy(['name' => 'Briefing'])->getUuid(),
            'needed' => [$foreignUuid => '2'],
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $shift = static::getContainer()->get(ShiftRepository::class)->findAll()[0];
        self::assertCount(1, $shift->getNeededVolunteerTypes(), 'a type another department claimed is honoured');
    }

    /**
     * Within one sort order the department's own types lead, so claiming a type is a shortcut rather
     * than a restriction. The pinned base types still outrank both.
     */
    public function testThisDepartmentsTypesLeadThePickerWithoutOutrankingTheBaseTypes(): void
    {
        $this->login();
        $this->task('Briefing', $this->alpha);

        $staff = (new VolunteerType('Staff'))->setSortOrder(10);
        $ours = new VolunteerType('Zulu Rigging');
        $theirs = new VolunteerType('Bravo Audio');
        $unclaimed = new VolunteerType('Runner');
        foreach ([$staff, $ours, $theirs, $unclaimed] as $type) {
            $this->em->persist($type);
        }
        $this->alpha->addVolunteerType($ours);
        $this->bravo->addVolunteerType($theirs);
        $this->em->flush();
        $expected = array_map(
            static fn (VolunteerType $t) => 'needed['.$t->getUuid().']',
            [$staff, $ours, $theirs, $unclaimed],
        );
        $this->em->clear();
        $this->reloadDepartments();

        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->alpha->getUuid());
        $names = $crawler->filter('#planner-add-modal input[type="number"]')->each(static fn ($n) => $n->attr('name'));

        self::assertSame($expected, $names);
    }

    /** The base types head every picker regardless of where their names fall alphabetically. */
    public function testBaseTypesSortAheadOfTheRest(): void
    {
        $staff = (new VolunteerType('Staff'))->setSortOrder(10);
        $volunteer = (new VolunteerType('Volunteer'))->setSortOrder(20);
        $audio = new VolunteerType('Audio');
        foreach ([$audio, $staff, $volunteer] as $type) {
            $this->em->persist($type);
        }
        $this->em->flush();

        $ordered = array_map(
            static fn (VolunteerType $t) => $t->getName(),
            static::getContainer()->get(VolunteerTypeRepository::class)->findAllOrdered(),
        );

        self::assertSame(['Staff', 'Volunteer', 'Audio'], $ordered);
    }

    // ---- batch tools --------------------------------------------------------

    /**
     * The grid identifies a block by the shift's public uuid, so that is what the batch endpoints
     * have to resolve. Reading the id as an integer made every batch action a silent no-op.
     */
    public function testBatchSetsTheShiftTaskOnEverySelectedShift(): void
    {
        $this->login();
        $briefing = $this->task('Briefing', $this->alpha);
        $cleanup = $this->task('Cleanup', $this->alpha);

        $a = $this->shift($this->alpha, '2026-06-01 10:00', '2026-06-01 12:00');
        $b = $this->shift($this->alpha, '2026-06-01 13:00', '2026-06-01 15:00');
        $untouched = $this->shift($this->alpha, '2026-06-01 16:00', '2026-06-01 18:00');
        // A shift that already has a task is replaced, not skipped.
        $b->setShiftTask($cleanup);
        $this->em->flush();

        $this->client->request('POST', '/manage-shifts/planner/batch', [
            '_token' => $this->editToken($this->alpha),
            'ids' => [$a->getUuid(), $b->getUuid()],
            'task' => $briefing->getUuid(),
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $repo = static::getContainer()->get(ShiftRepository::class);
        self::assertSame('Briefing', $repo->find($a->getId())->getShiftTask()->getName());
        self::assertSame('Briefing', $repo->find($b->getId())->getShiftTask()->getName(), 'an existing task is replaced');
        self::assertNull($repo->find($untouched->getId())->getShiftTask(), 'unselected shift is untouched');
    }

    public function testBatchDeleteRemovesOnlyTheSelectedShifts(): void
    {
        $this->login();
        $a = $this->shift($this->alpha, '2026-06-01 10:00', '2026-06-01 12:00');
        $b = $this->shift($this->alpha, '2026-06-01 13:00', '2026-06-01 15:00');
        $keep = $this->shift($this->alpha, '2026-06-01 16:00', '2026-06-01 18:00');

        $this->client->request('POST', '/manage-shifts/planner/batch/delete', [
            '_token' => $this->editToken($this->alpha),
            'ids' => [$a->getUuid(), $b->getUuid()],
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $remaining = static::getContainer()->get(ShiftRepository::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame($keep->getId(), $remaining[0]->getId());
    }

    /** A manager cannot delete a shift they may not plan, even by naming its uuid directly. */
    public function testBatchDeleteIgnoresShiftsOutsideTheManagersScope(): void
    {
        $this->loginScopedTo($this->alpha);

        $mine = $this->shift($this->alpha, '2026-06-01 10:00', '2026-06-01 12:00');
        $theirs = $this->shift($this->bravo, '2026-06-01 10:00', '2026-06-01 12:00');

        $this->client->request('POST', '/manage-shifts/planner/batch/delete', [
            '_token' => $this->editToken($this->alpha),
            'ids' => [$mine->getUuid(), $theirs->getUuid()],
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $repo = static::getContainer()->get(ShiftRepository::class);
        self::assertNull($repo->find($mine->getId()));
        self::assertNotNull($repo->find($theirs->getId()), "another department's shift survives");
    }

    // ---- discarding drafts --------------------------------------------------

    /** Published shifts may already be assigned, so discarding drafts must never reach them. */
    public function testDiscardingDraftsKeepsPublishedShiftsAndOtherDepartments(): void
    {
        $this->login();
        $draft = $this->shift($this->alpha, '2026-06-01 10:00', '2026-06-01 12:00');
        $published = $this->shift($this->alpha, '2026-06-01 13:00', '2026-06-01 15:00', ShiftState::PUBLISHED);
        $otherDraft = $this->shift($this->bravo, '2026-06-01 10:00', '2026-06-01 12:00');

        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->alpha->getUuid());
        $token = $crawler->filter('form[action*="/drafts/discard"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/manage-shifts/planner/drafts/discard', [
            '_token' => $token,
            'department' => $this->alpha->getUuid(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, json_decode((string) $this->client->getResponse()->getContent(), true)['deleted']);

        $this->em->clear();
        $repo = static::getContainer()->get(ShiftRepository::class);
        self::assertNull($repo->find($draft->getId()));
        self::assertNotNull($repo->find($published->getId()), 'a published shift is not a draft');
        self::assertNotNull($repo->find($otherDraft->getId()), "another department's draft is not touched");
    }

    /**
     * shift:manage is department-scoped, and this endpoint wipes a whole department's drafts, so it
     * has to be re-checked against the department named in the request rather than trusted because
     * the caller reached the module at all.
     */
    public function testDiscardingDraftsOfADepartmentTheManagerCannotPlanIsDenied(): void
    {
        $this->loginScopedTo($this->alpha);
        $theirDraft = $this->shift($this->bravo, '2026-06-01 10:00', '2026-06-01 12:00');

        $token = $this->client->request('GET', '/manage-shifts/planner?department='.$this->alpha->getUuid())
            ->filter('form[action*="/drafts/discard"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/manage-shifts/planner/drafts/discard', [
            '_token' => $token,
            'department' => $this->bravo->getUuid(),
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull(static::getContainer()->get(ShiftRepository::class)->find($theirDraft->getId()));
    }
}
