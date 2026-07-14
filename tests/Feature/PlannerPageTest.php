<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Repository\ShiftRepository;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * End-to-end Standard Planner: the grid renders for a manager and
 * painting creates a consolidated draft shift.
 */
final class PlannerPageTest extends DatabaseWebTestCase
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

    public function testPlannerGridRenders(): void
    {
        $this->manager();
        $this->department();
        $this->em->flush();

        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'mgr@example.com']));
        $crawler = $this->client->request('GET', '/manage-shifts/planner');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.planner-grid')->count());
        self::assertGreaterThan(0, $crawler->filter('.planner-day-body')->count());
    }

    /**
     * Setup/teardown days label their phase on the line above the date, so a manager scanning the
     * header can tell them apart from the main event days at a glance.
     */
    public function testDayHeaderShowsPhaseAboveTheDate(): void
    {
        $this->manager();
        $this->department();
        $this->em->flush();

        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_BUILDUP_START, '2026-08-14T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_EVENT_START, '2026-08-16T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_EVENT_END, '2026-08-18T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_TEARDOWN_END, '2026-08-19T00:00:00+00:00');

        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'mgr@example.com']));
        $crawler = $this->client->request('GET', '/manage-shifts/planner');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.planner-phase-teardown')->count());

        $head = $crawler->filter('.planner-phase-setup .planner-day-head')->first();
        self::assertSame('setup', $head->filter('.planner-phase-tag')->text());
        self::assertSame('Fri 14 Aug', $head->filter('.planner-day-label')->text());
        self::assertLessThan(
            strpos($head->html(), 'planner-day-label'),
            strpos($head->html(), 'planner-phase-tag'),
            'the phase tag renders above the date, not beside it',
        );
    }

    public function testPaintCreatesConsolidatedDraft(): void
    {
        $this->manager();
        $dept = $this->department();
        $this->em->flush();
        $deptId = $dept->getUuid();

        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'mgr@example.com']));

        // Fetch the planner to obtain a valid paint CSRF token.
        $crawler = $this->client->request('GET', '/manage-shifts/planner');
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
                'intervals' => [
                    ['start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T11:00:00'],
                    ['start' => '2026-06-01T11:00:00', 'end' => '2026-06-01T12:00:00'],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertSame(1, $payload['created'], 'touching intervals consolidate into one draft');

        $shifts = static::getContainer()->get(ShiftRepository::class)->findAll();
        self::assertCount(1, $shifts);
        self::assertSame(ShiftState::DRAFT, $shifts[0]->getState());
        self::assertSame(2.0, $shifts[0]->getDurationHours());
    }
}
