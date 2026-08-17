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
 * Creating a shift task from the planner, where the shift that needs it is being made.
 *
 * The task picker also offers only what belongs here: the department's own tasks plus the global
 * ones. Offering every department's tasks lets a manager attach another department's task to their
 * shift.
 */
final class PlannerTaskCreationTest extends DatabaseWebTestCase
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

    /** @param string[] $privileges */
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

        $this->em->clear();
        $fresh = $this->em->getRepository(User::class)->findOneBy(['email' => $user->getEmail()]);
        $this->client->loginUser($fresh);

        return $fresh;
    }

    private function plannerToken(Department $department): string
    {
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$department->getUuid());
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-planner-edit-token-value]')->attr('data-planner-edit-token-value');
    }

    public function testAManagerCanCreateAShiftTaskFromThePlanner(): void
    {
        $this->login(['shift:manage', 'manageshifts:view'], $this->alpha);
        $token = $this->plannerToken($this->alpha);

        $this->client->request('POST', '/manage-shifts/planner/task', [
            '_token' => $token,
            'department' => $this->alpha->getUuid(),
            'name' => 'Briefing',
        ]);

        self::assertResponseIsSuccessful();
        $created = $this->tasks()->findOneBy(['name' => 'Briefing']);
        self::assertNotNull($created, 'a task must be creatable where the shift that needs it is created');
        self::assertSame($this->alpha->getId(), $created->getDepartment()?->getId());
    }

    public function testThePlannerOffersTheCreateControl(): void
    {
        $this->login(['shift:manage', 'manageshifts:view'], $this->alpha);

        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->alpha->getUuid());

        self::assertGreaterThan(
            0,
            $crawler->filter('form[action*="/planner/task"]')->count(),
            'the planner must offer a way to create a task, or a manager has to go and find an admin',
        );
    }

    public function testAManagerCannotCreateATaskForADepartmentTheyDoNotManage(): void
    {
        $this->login(['shift:manage', 'manageshifts:view'], $this->alpha);
        $token = $this->plannerToken($this->alpha);

        $this->client->request('POST', '/manage-shifts/planner/task', [
            '_token' => $token,
            'department' => $this->bravo->getUuid(),
            'name' => 'Smuggled',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->tasks()->findOneBy(['name' => 'Smuggled']));
    }

    public function testTaskCreationIsRefusedWithoutAValidCsrfToken(): void
    {
        $this->login(['shift:manage', 'manageshifts:view'], $this->alpha);

        $this->client->request('POST', '/manage-shifts/planner/task', [
            '_token' => 'not-a-real-token',
            'department' => $this->alpha->getUuid(),
            'name' => 'No Token',
        ]);

        self::assertNull($this->tasks()->findOneBy(['name' => 'No Token']));
    }

    /**
     * The picker offers this department's tasks and the global ones, never another department's.
     * Options render as "Department: Task" for a department task and as the bare name for a global
     * one, so the assertions match on the task name inside that text.
     */
    public function testTheTaskPickerShowsOnlyThisDepartmentsTasksAndTheGlobalOnes(): void
    {
        $this->task('Alpha Task', $this->alpha);
        $this->task('Bravo Task', $this->bravo);
        $this->task('Shared', null);

        $this->login(['shift:manage', 'manageshifts:view'], $this->alpha);
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->alpha->getUuid());

        $options = implode(' | ', $crawler->filter('#paint-task option')->each(fn ($o) => $o->text()));
        self::assertStringContainsString('Alpha Task', $options);
        self::assertStringContainsString('Shared', $options);
        self::assertStringNotContainsString('Bravo Task', $options, "another department's task must not be attachable to this shift");
    }
}
