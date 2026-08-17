<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The optional shift description: it is authored in the planner and read back on the
 * volunteer-facing surfaces, the public API and the calendar feed.
 */
final class ShiftDescriptionTest extends DatabaseWebTestCase
{
    private Department $dept;

    private function loginManager(): Department
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

        $this->client->loginUser($user);

        return $this->dept = $dept;
    }

    private function task(): ShiftTask
    {
        $task = new ShiftTask('Briefing');
        $task->setDepartment($this->dept);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function draft(?string $description = null): Shift
    {
        $shift = (new Shift())->setTitle('S')
            ->setDescription($description)
            ->setStartsAt(new \DateTimeImmutable('2026-06-01 10:00'))
            ->setEndsAt(new \DateTimeImmutable('2026-06-01 12:00'))
            ->setDepartment($this->dept)
            ->setState(ShiftState::DRAFT);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    private function editToken(): string
    {
        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->dept->getUuid());

        return $crawler->filter('.planner')->attr('data-planner-edit-token-value');
    }

    private function reload(Shift $shift): Shift
    {
        $this->em->clear();

        return $this->em->getRepository(Shift::class)->find($shift->getId());
    }

    public function testPlannerCreateStoresTheDescription(): void
    {
        $dept = $this->loginManager();
        $task = $this->task();

        $this->client->request('POST', '/manage-shifts/planner/create', [
            '_token' => $this->editToken(),
            'department' => $dept->getUuid(),
            'task' => $task->getUuid(),
            'start' => '2026-06-01T10:00',
            'end' => '2026-06-01T12:00',
            'description' => "Bring a radio.\nMeet at the loading bay.",
        ]);

        self::assertResponseIsSuccessful();
        $created = $this->em->getRepository(Shift::class)->findOneBy(['department' => $dept->getId()]);
        self::assertSame("Bring a radio.\nMeet at the loading bay.", $created->getDescription());
    }

    public function testPlannerEditStoresTheDescription(): void
    {
        $this->loginManager();
        $shift = $this->draft();

        $this->client->request('POST', '/manage-shifts/planner/shift/'.$shift->getUuid().'/edit', [
            '_token' => $this->editToken(),
            'description' => 'Hi-vis vest required.',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Hi-vis vest required.', $this->reload($shift)->getDescription());
    }

    /**
     * The batch and single-shift endpoints share one action, and only some of them post every
     * field. A payload that omits the description must not silently erase what a planner wrote.
     */
    public function testPlannerEditWithoutTheFieldKeepsTheDescription(): void
    {
        $this->loginManager();
        $shift = $this->draft('Keep me.');

        $this->client->request('POST', '/manage-shifts/planner/shift/'.$shift->getUuid().'/edit', [
            '_token' => $this->editToken(),
            'title' => 'Renamed',
        ]);

        self::assertResponseIsSuccessful();
        $updated = $this->reload($shift);
        self::assertSame('Renamed', $updated->getTitle());
        self::assertSame('Keep me.', $updated->getDescription());
    }

    public function testPlannerEditWithAnEmptyFieldClearsTheDescription(): void
    {
        $this->loginManager();
        $shift = $this->draft('Remove me.');

        $this->client->request('POST', '/manage-shifts/planner/shift/'.$shift->getUuid().'/edit', [
            '_token' => $this->editToken(),
            'description' => '   ',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->reload($shift)->getDescription());
    }

    /**
     * The planner writes entities directly, so the entity's length constraint never runs on this
     * path: without an explicit guard an unbounded description reaches the database and every
     * public surface that renders it.
     */
    public function testPlannerRejectsAnOverlongDescription(): void
    {
        $this->loginManager();
        $shift = $this->draft('Short.');

        $this->client->request('POST', '/manage-shifts/planner/shift/'.$shift->getUuid().'/edit', [
            '_token' => $this->editToken(),
            'description' => str_repeat('a', Shift::DESCRIPTION_MAX_LENGTH + 1),
        ]);

        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['ok']);
        self::assertSame('Short.', $this->reload($shift)->getDescription(), 'a rejected edit leaves the shift untouched');
    }

    public function testPublicApiExposesTheDescriptionOfPublicShiftsOnly(): void
    {
        $scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
        $scenario->shift('Public Shift')->setDescription('Wear closed shoes.');
        $scenario->shift('Draft Plan', 'tomorrow 10:00', '+2 hours', 2, ShiftState::DRAFT)
            ->setDescription('Internal note.');
        $this->em->flush();

        $this->client->request('GET', '/api/v0-beta/shifts');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $data = json_decode($body, true)['data'];

        self::assertSame('Wear closed shoes.', $data[0]['description']);
        self::assertStringNotContainsString('Internal note.', $body, 'a draft description must not reach the public API');
    }

    public function testIcalCarriesTheDescription(): void
    {
        $scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
        $user = $scenario->user(['export:ical'], $scenario->type);
        $shift = $scenario->shift('Night Watch');
        $shift->setDescription('Report to the info desk first.');
        $scenario->signUp($user, $shift);
        $this->em->flush();

        $this->client->request('GET', '/api/ical', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$user->getApiKey()]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('DESCRIPTION:Report to the info desk first.', (string) $this->client->getResponse()->getContent());
    }

    /**
     * RFC 5545 caps a content line at 75 octets. A description long enough to exceed it must be
     * folded, or a strict calendar client rejects the entire feed over the one line. Unfolding it
     * (dropping each CRLF and the single leading space that follows) has to give the text back
     * unchanged.
     */
    public function testIcalFoldsLongLines(): void
    {
        $scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
        $user = $scenario->user(['export:ical'], $scenario->type);
        $shift = $scenario->shift('Night Watch');
        $shift->setDescription(str_repeat('long ', 60));
        $scenario->signUp($user, $shift);
        $this->em->flush();

        $this->client->request('GET', '/api/ical', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$user->getApiKey()]);

        $body = (string) $this->client->getResponse()->getContent();
        foreach (explode("\r\n", trim($body)) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line), 'iCalendar content line exceeds 75 octets');
        }
        self::assertStringContainsString(trim(str_repeat('long ', 60)), str_replace("\r\n ", '', $body));
    }

    public function testBrowseListShowsATruncatedDescription(): void
    {
        $scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
        $user = $scenario->user(['shift:view', 'shift:self'], $scenario->type);
        $scenario->shift('Public Shift')->setDescription(str_repeat('x', 200));
        $this->em->flush();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/shifts');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString(str_repeat('x', 120).'…', $text);
        self::assertStringNotContainsString(str_repeat('x', 121), $text, 'the list shows a preview, not the whole description');
    }
}
