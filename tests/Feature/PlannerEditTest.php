<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Enum\ShiftAudience;
use App\Enum\ShiftState;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Planner side-panel edit and batch-edit scope: a batch change
 * applies only to the selected shifts, never to others.
 */
final class PlannerEditTest extends DatabaseWebTestCase
{
    private Department $dept;

    private function login(): Department
    {
        $group = new Group('Managers', 'mgr-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:manage'] as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('mgr')->setEmail('mgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        $dept = new Department('Logistics', 'logistics');
        $this->em->persist($dept);
        $this->em->flush();

        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'mgr@example.com']));

        return $this->dept = $dept;
    }

    /** A shift task the department may use; every planner surface now requires one. */
    private function task(string $name = 'Briefing'): ShiftTask
    {
        $task = new ShiftTask($name);
        $task->setDepartment($this->dept);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function draft(Department $dept, string $start, string $end): Shift
    {
        $shift = (new Shift())->setTitle('S')
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setDepartment($dept)
            ->setState(ShiftState::DRAFT);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    /** Fetch a live planner_edit CSRF token from the rendered planner page. */
    private function editToken(): string
    {
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->dept->getUuid());

        return $crawler->filter('.planner')->attr('data-planner-edit-token-value');
    }

    /**
     * The identifier the planner renders for the department must be the one the paint endpoint
     * accepts: the grid reads it straight out of this attribute and posts it back, so the two must
     * not drift apart (e.g. a uuid attribute against a client that coerces it to a number).
     */
    public function testPaintCreatesDraftFromTheDepartmentIdentifierRenderedOnThePage(): void
    {
        $dept = $this->login();
        $task = $this->task();
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $planner = $crawler->filter('.planner');

        $this->client->request('POST', '/manage-shifts/planner/paint', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            '_token' => $planner->attr('data-planner-paint-token-value'),
            'department' => $planner->attr('data-planner-department-value'),
            'task' => $task->getUuid(),
            'intervals' => [['start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T12:00:00']],
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'created' => 1], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testBatchDurationAppliesOnlyToSelectedShifts(): void
    {
        $dept = $this->login();
        $a = $this->draft($dept, '2026-06-01 10:00', '2026-06-01 11:00'); // 1h
        $b = $this->draft($dept, '2026-06-01 12:00', '2026-06-01 13:00'); // 1h
        $untouched = $this->draft($dept, '2026-06-01 14:00', '2026-06-01 15:00'); // 1h

        $this->client->request('POST', '/manage-shifts/planner/batch', [
            '_token' => $this->editToken(),
            'ids' => [$a->getUuid(), $b->getUuid()],
            'duration_minutes' => 120,
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $repo = $this->em->getRepository(Shift::class);
        self::assertSame(2.0, $repo->find($a->getId())->getDurationHours());
        self::assertSame(2.0, $repo->find($b->getId())->getDurationHours());
        self::assertSame(1.0, $repo->find($untouched->getId())->getDurationHours(), 'unselected shift is unchanged');
    }

    public function testBatchSetsRequiredVolunteerTypeOnSelection(): void
    {
        $dept = $this->login();
        $type = new VolunteerType('Crew');
        $this->em->persist($type);
        $a = $this->draft($dept, '2026-06-01 10:00', '2026-06-01 12:00');
        $this->em->flush();

        $this->client->request('POST', '/manage-shifts/planner/batch', [
            '_token' => $this->editToken(),
            'ids' => [$a->getUuid()],
            'needed_type' => $type->getUuid(),
            'needed_count' => 3,
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $shift = $this->em->getRepository(Shift::class)->find($a->getId());
        self::assertCount(1, $shift->getNeededVolunteerTypes());
        self::assertSame(3, $shift->getNeededVolunteerTypes()->first()->getCount());
    }

    public function testSinglePanelAndEditUpdateFields(): void
    {
        $dept = $this->login();
        $shift = $this->draft($dept, '2026-06-01 10:00', '2026-06-01 12:00');

        // Panel renders the edit form.
        $this->client->request('GET', '/manage-shifts/planner/shift/'.$shift->getUuid().'/panel');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/manage-shifts/planner/shift/'.$shift->getUuid().'/edit', [
            '_token' => $this->editToken(),
            'title' => 'Renamed shift',
            'audience' => ShiftAudience::ALL_STAFF->value,
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $updated = $this->em->getRepository(Shift::class)->find($shift->getId());
        self::assertSame('Renamed shift', $updated->getTitle());
        self::assertSame(ShiftAudience::ALL_STAFF, $updated->getAudience());
    }

    /**
     * The check-in override forces the event check-in requirement on a shift outside the main event,
     * and the panel offers it read-only. A read-only checkbox submits nothing, exactly like an
     * unticked one, so an edit that never touched it must not be read as switching it off: the
     * manager renames a shift and the override they can see ticked is gone.
     */
    public function testSavingThePanelLeavesAnUnsubmittedCheckInOverrideAlone(): void
    {
        $dept = $this->login();
        $shift = $this->draft($dept, '2026-06-01 10:00', '2026-06-01 12:00');
        $shift->setRequireCheckin(true);
        $this->em->flush();

        $this->client->request('POST', '/manage-shifts/planner/shift/'.$shift->getUuid().'/edit', [
            '_token' => $this->editToken(),
            'title' => 'Renamed shift',
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $updated = $this->em->getRepository(Shift::class)->find($shift->getId());
        self::assertSame('Renamed shift', $updated->getTitle());
        self::assertTrue($updated->isRequireCheckin());
    }

    /** Leaving an absent field alone must not make a submitted one unwritable. */
    public function testAnExplicitCheckInOverrideStillApplies(): void
    {
        $dept = $this->login();
        $shift = $this->draft($dept, '2026-06-01 10:00', '2026-06-01 12:00');

        $this->client->request('POST', '/manage-shifts/planner/shift/'.$shift->getUuid().'/edit', [
            '_token' => $this->editToken(),
            'require_checkin' => '1',
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertTrue($this->em->getRepository(Shift::class)->find($shift->getId())->isRequireCheckin());

        $this->client->request('POST', '/manage-shifts/planner/shift/'.$shift->getUuid().'/edit', [
            '_token' => $this->editToken(),
            'require_checkin' => '0',
        ]);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertFalse($this->em->getRepository(Shift::class)->find($shift->getId())->isRequireCheckin());
    }
}
