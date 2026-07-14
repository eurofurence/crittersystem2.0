<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\UserGroupAssignment;
use App\Repository\ShiftTaskRepository;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Who may create and change shift tasks.
 *
 * A task belongs to a department, or is global (no department) and shared by all of them.
 * `shift:manage` is department-scoped, but the class-level check on the management screen passes for
 * anyone holding it anywhere — so each action must re-check the task's own department. Without that,
 * a manager delegated to one department can edit and delete another department's tasks and the
 * global ones.
 */
final class ShiftTaskAccessTest extends DatabaseWebTestCase
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

    private function tasks(): ShiftTaskRepository
    {
        return static::getContainer()->get(ShiftTaskRepository::class);
    }

    private function task(string $name, ?Department $department): ShiftTask
    {
        $task = new ShiftTask($name);
        $task->setDepartment($department);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    /**
     * A user holding the privileges, scoped to $scope when given. A department-scoped assignment is
     * what makes somebody a *delegated* manager of that department only.
     *
     * @param string[] $privileges
     */
    private function login(array $privileges, ?Department $scope = null): User
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
        $this->em->persist(new UserGroupAssignment($user, $group, $scope));
        $this->em->flush();

        // Reload: persisting the assignment directly does not populate the in-memory user, and a
        // user with no groups has no privileges at all.
        $this->em->clear();
        $fresh = $this->em->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]);
        $this->client->loginUser($fresh);

        return $fresh;
    }

    private function submitNew(string $name, ?Department $department): void
    {
        $crawler = $this->client->request('GET', '/manage/shift-tasks/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $form['shift_task[name]'] = $name;
        $form['shift_task[department]'] = $department !== null ? (string) $department->getId() : '';
        $this->client->submit($form);
    }

    public function testADelegatedManagerCreatesATaskForTheirOwnDepartment(): void
    {
        $this->login(['shift:manage'], $this->alpha);

        $this->submitNew('Briefing', $this->alpha);

        $created = $this->tasks()->findOneBy(['name' => 'Briefing']);
        self::assertNotNull($created, 'a shift manager must be able to create a task for their department');
        self::assertSame($this->alpha->getId(), $created->getDepartment()?->getId());
    }

    public function testTwoDepartmentsCanEachHaveATaskOfTheSameName(): void
    {
        // A globally unique name would let the first department to claim "Briefing" block the rest.
        $this->task('Briefing', $this->bravo);

        $this->login(['shift:manage'], $this->alpha);
        $this->submitNew('Briefing', $this->alpha);

        self::assertCount(2, $this->tasks()->findBy(['name' => 'Briefing']));
    }

    public function testTheFormOnlyOffersDepartmentsTheUserManages(): void
    {
        $this->login(['shift:manage'], $this->alpha);

        $crawler = $this->client->request('GET', '/manage/shift-tasks/new');

        $options = $crawler->filter('select[name="shift_task[department]"] option')->each(fn ($o) => $o->text());
        self::assertContains('Alpha', $options);
        self::assertNotContains('Bravo', $options, "another department must not be offered as the task's owner");
    }

    public function testADelegatedManagerCannotCreateATaskForAnotherDepartment(): void
    {
        $this->login(['shift:manage'], $this->alpha);

        // The choice list omits Bravo, so post its id directly — a submitted id is user input.
        $this->client->request('POST', '/manage/shift-tasks/new', [
            'shift_task' => ['name' => 'Smuggled', 'department' => (string) $this->bravo->getId()],
        ]);

        self::assertNull($this->tasks()->findOneBy(['name' => 'Smuggled']));
    }

    public function testOnlyAnAdminCanCreateAGlobalTask(): void
    {
        $this->login(['shift:manage'], $this->alpha);

        $this->client->request('POST', '/manage/shift-tasks/new', [
            'shift_task' => ['name' => 'Global Grab', 'department' => ''],
        ]);

        self::assertNull(
            $this->tasks()->findOneBy(['name' => 'Global Grab']),
            'a global task is shared by every department, so it stays an admin decision',
        );
    }

    public function testAnAdminCanCreateAGlobalTask(): void
    {
        $this->login(['global:admin', 'shift:manage']);

        $this->submitNew('Everywhere', null);

        $created = $this->tasks()->findOneBy(['name' => 'Everywhere']);
        self::assertNotNull($created);
        self::assertNull($created->getDepartment());
    }

    public function testADelegatedManagerCannotEditAnotherDepartmentsTask(): void
    {
        $foreign = $this->task('Bravo Task', $this->bravo);
        $this->login(['shift:manage'], $this->alpha);

        $this->client->request('GET', '/manage/shift-tasks/'.$foreign->getUuid().'/edit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testADelegatedManagerCannotEditAGlobalTask(): void
    {
        $global = $this->task('Shared', null);
        $this->login(['shift:manage'], $this->alpha);

        $this->client->request('GET', '/manage/shift-tasks/'.$global->getUuid().'/edit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testADelegatedManagerCannotDeleteAnotherDepartmentsTask(): void
    {
        $foreign = $this->task('Bravo Task', $this->bravo);
        $this->login(['shift:manage'], $this->alpha);

        $this->client->request('POST', '/manage/shift-tasks/'.$foreign->getUuid().'/delete', [
            '_token' => 'whatever',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->tasks()->findOneBy(['name' => 'Bravo Task']), "another department's task must survive");
    }

    public function testTheListShowsOnlyTheUsersOwnDepartmentAndGlobalTasks(): void
    {
        $this->task('Alpha Task', $this->alpha);
        $this->task('Bravo Task', $this->bravo);
        $this->task('Shared', null);

        $this->login(['shift:manage'], $this->alpha);
        $this->client->request('GET', '/manage/shift-tasks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alpha Task');
        self::assertSelectorTextContains('body', 'Shared');
        self::assertSelectorTextNotContains('body', 'Bravo Task');
    }

    public function testAnAdminSeesEveryTask(): void
    {
        $this->task('Alpha Task', $this->alpha);
        $this->task('Bravo Task', $this->bravo);

        $this->login(['global:admin', 'shift:manage']);
        $this->client->request('GET', '/manage/shift-tasks');

        self::assertSelectorTextContains('body', 'Alpha Task');
        self::assertSelectorTextContains('body', 'Bravo Task');
    }
}
