<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Repository\ShiftRepository;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Parallel shifts on the Standard Planner.
 *
 * A department routinely runs several different shifts over the same hours. They were always legal
 * in the model, but the grid drew them on top of each other, so every one but the last looked
 * deleted. These protect both halves: the server keeps accepting them, and the grid gives each one
 * its own lane.
 */
final class PlannerOverlappingShiftsTest extends DatabaseWebTestCase
{
    private function manager(): User
    {
        $group = new Group('Managers', 'managers-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
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

        return $user;
    }

    private function department(): Department
    {
        $dept = new Department('Logistics', 'logistics');
        $this->em->persist($dept);

        return $dept;
    }

    /** Pin the visible range so the seeded day is always a rendered column. */
    private function setRange(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_BUILDUP_START, '2026-06-01T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_EVENT_START, '2026-06-01T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_EVENT_END, '2026-06-02T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_TEARDOWN_END, '2026-06-02T00:00:00+00:00');
    }

    private function seedShift(Department $dept, string $start, string $end, string $title): Shift
    {
        $tz = new \DateTimeZone('UTC');
        $shift = (new Shift())
            ->setTitle($title)
            ->setStartsAt(new \DateTimeImmutable($start, $tz))
            ->setEndsAt(new \DateTimeImmutable($end, $tz))
            ->setState(ShiftState::DRAFT)
            ->setDepartment($dept);
        $this->em->persist($shift);

        return $shift;
    }

    private function login(): void
    {
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'mgr@example.com']));
    }

    /**
     * The reported bug: adding a shift over an existing one must produce a SECOND shift and leave
     * the first exactly where it was. It used to reschedule the first instead, because a drag
     * starting on a block was read as a move.
     */
    public function testPaintingOverAnExistingShiftCreatesASecondOneAndLeavesTheFirstAlone(): void
    {
        $this->manager();
        $dept = $this->department();
        $existing = $this->seedShift($dept, '2026-06-01 22:00', '2026-06-01 23:30', 'Door');
        $task = new ShiftTask('Briefing');
        $task->setDepartment($dept);
        $this->em->persist($task);
        $this->setRange();
        $this->em->flush();
        $deptId = $dept->getUuid();
        $existingId = $existing->getId();

        $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$deptId);
        $token = $crawler->filter('.planner')->attr('data-planner-paint-token-value');

        $this->client->request(
            'POST',
            '/manage-shifts/planner/paint',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_token' => $token,
                'department' => $deptId,
                'audience' => 'department_staff',
                'task' => $task->getUuid(),
                'intervals' => [['start' => '2026-06-01T22:00:00', 'end' => '2026-06-01T23:30:00']],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $shifts = static::getContainer()->get(ShiftRepository::class)->findAll();
        self::assertCount(2, $shifts, 'the overlapping paint must add a shift, not replace one');

        $this->em->clear();
        $reloaded = static::getContainer()->get(ShiftRepository::class)->find($existingId);
        self::assertNotNull($reloaded, 'the existing shift must not be deleted');
        self::assertSame('2026-06-01 22:00', $reloaded->getStartsAt()->format('Y-m-d H:i'));
        self::assertSame('2026-06-01 23:30', $reloaded->getEndsAt()->format('Y-m-d H:i'));
    }

    /**
     * The rendering half, and the guard that fails against the old grid: two shifts at the same time
     * must be drawn side by side. Before lanes existed every block was `left: 3px; right: 3px`, so
     * both sat in exactly the same place and only one was reachable.
     */
    public function testParallelShiftsRenderSideBySide(): void
    {
        $this->manager();
        $dept = $this->department();
        $this->seedShift($dept, '2026-06-01 22:00', '2026-06-01 23:30', 'Door');
        $this->seedShift($dept, '2026-06-01 22:00', '2026-06-01 23:30', 'Tech');
        $this->setRange();
        $this->em->flush();

        $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        self::assertResponseIsSuccessful();

        $blocks = $crawler->filter('.planner-block');
        self::assertCount(2, $blocks, 'both parallel shifts render');

        $styles = $blocks->each(static fn ($node): string => (string) $node->attr('style'));
        $lefts = [];
        foreach ($styles as $style) {
            self::assertMatchesRegularExpression('/left:\s*[\d.]+%/', $style, 'each block is placed in a lane');
            self::assertMatchesRegularExpression('/width:\s*calc\([\d.]+%/', $style);
            preg_match('/left:\s*([\d.]+)%/', $style, $m);
            $lefts[] = $m[1];
        }
        self::assertCount(2, array_unique($lefts), 'parallel shifts must not share the same horizontal position');

        // Both are half-width, and both carry the shared-lane marker.
        self::assertCount(2, $crawler->filter('.planner-block-shared'));
        foreach ($styles as $style) {
            self::assertStringContainsString('width: calc(50%', $style);
        }
    }

    public function testConsecutiveShiftsKeepTheFullWidth(): void
    {
        $this->manager();
        $dept = $this->department();
        $this->seedShift($dept, '2026-06-01 10:00', '2026-06-01 12:00', 'Early');
        $this->seedShift($dept, '2026-06-01 12:00', '2026-06-01 14:00', 'Late');
        $this->setRange();
        $this->em->flush();

        $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.planner-block-shared'), 'touching shifts do not share a lane');
        foreach ($crawler->filter('.planner-block')->each(static fn ($n): string => (string) $n->attr('style')) as $style) {
            self::assertStringContainsString('left: 0%', $style);
            self::assertStringContainsString('width: calc(100%', $style);
        }
    }

    /**
     * A busy day renders every parallel shift and widens its column to fit them. Capping the day at
     * four and hiding the rest behind an "expand" link made a fully planned day read as half empty.
     */
    public function testEveryParallelShiftIsRenderedAndTheColumnIsSizedForThem(): void
    {
        $this->manager();
        $dept = $this->department();
        for ($i = 0; $i < 6; ++$i) {
            $this->seedShift($dept, '2026-06-01 22:00', '2026-06-01 23:30', 'Shift '.$i);
        }
        $this->setRange();
        $this->em->flush();

        $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        self::assertResponseIsSuccessful();

        self::assertCount(6, $crawler->filter('.planner-block'));
        self::assertStringContainsString(
            '--planner-lanes: 6',
            (string) $crawler->filter('.planner-day')->first()->attr('style'),
            'the column is sized for the busiest moment of the day',
        );
        self::assertCount(0, $crawler->filter('.planner-day-toggle'), 'there is nothing left to expand');
    }

    /** A quiet day is one lane wide, so it does not take the width of a busy neighbour. */
    public function testAQuietDayStaysOneLaneWide(): void
    {
        $this->manager();
        $dept = $this->department();
        $this->seedShift($dept, '2026-06-01 10:00', '2026-06-01 12:00', 'Door');
        $this->seedShift($dept, '2026-06-01 14:00', '2026-06-01 16:00', 'Tech');
        $this->setRange();
        $this->em->flush();

        $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('--planner-lanes: 1', (string) $crawler->filter('.planner-day')->first()->attr('style'));
    }

    /** No query parameter may be reflected into the page, whether or not the planner reads it. */
    public function testAJunkQueryParameterIsNotReflected(): void
    {
        $this->manager();
        $dept = $this->department();
        $this->seedShift($dept, '2026-06-01 22:00', '2026-06-01 23:30', 'Door');
        $this->setRange();
        $this->em->flush();

        $this->login();
        $this->client->request(
            'GET',
            '/manage-shifts/planner?department='.$dept->getUuid().'&expand='.urlencode('"><script>x</script>'),
        );

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<script>x</script>', (string) $this->client->getResponse()->getContent());
    }

    /** The mode toggle is what makes painting over a block possible; Select must stay the default. */
    public function testThePlannerOffersAPaintModeAndDefaultsToSelect(): void
    {
        $this->manager();
        $dept = $this->department();
        $this->setRange();
        $this->em->flush();

        $this->login();
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#planner-mode-paint'));
        $select = $crawler->filter('#planner-mode-select');
        self::assertCount(1, $select);
        self::assertNotNull($select->attr('checked'), 'Select is the default, so existing drags keep moving shifts');
    }
}
