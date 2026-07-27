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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What the planner tells the manager after a publish attempt.
 *
 * Two things went wrong before: the draft counter and the publish button were rendered outside the
 * grid and never refreshed, so after a successful publish the button stayed live over zero drafts
 * and the next click answered "there are no draft shifts to publish"; and a rejected publish listed
 * the problems as text only, leaving the manager to find the offending blocks by eye.
 */
final class PlannerPublishFeedbackTest extends DatabaseWebTestCase
{
    private Department $dept;
    private bool $signedIn = false;

    /** @param string[] $privileges */
    private function login(array $privileges = ['manageshifts:view', 'shift:manage', 'shift:publish']): void
    {
        $group = new Group('Managers', 'mgr-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach ($privileges as $p) {
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

        $this->dept = new Department('Logistics', 'logistics');
        $this->em->persist($this->dept);
    }

    private function draft(string $title = 'Door'): Shift
    {
        $task = new ShiftTask('General-'.bin2hex(random_bytes(2)));
        $this->em->persist($task);

        $shift = (new Shift())->setTitle($title)
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($this->dept)->setShiftTask($task)->setState(ShiftState::DRAFT);
        $this->em->persist($shift);

        return $shift;
    }

    /** Signs in on first use: the user only exists once the test has flushed its fixtures. */
    private function plannerBar(): \Symfony\Component\DomCrawler\Crawler
    {
        if (!$this->signedIn) {
            $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'mgr@example.com']));
            $this->signedIn = true;
        }

        $crawler = $this->client->request('GET', '/manage-shifts/planner?department='.$this->dept->getUuid());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function publish(string $token): array
    {
        $this->client->request('POST', '/manage-shifts/planner/publish', [
            '_token' => $token,
            'department' => $this->dept->getUuid(),
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * The counter and the button must live in a swappable region, and must reflect the drafts that
     * actually exist. Without the region the planner has nothing to refresh after an edit.
     */
    public function testTheDraftCounterAndPublishButtonSitInARefreshableRegion(): void
    {
        $this->login();
        $this->draft();
        $this->draft('Tech');
        $this->em->flush();

        $crawler = $this->plannerBar();

        $bar = $crawler->filter('#planner-publish-bar');
        self::assertCount(1, $bar, 'the planner refreshes this region by id after every edit');
        self::assertStringContainsString('2', $bar->text(), 'two drafts are reported');
        self::assertCount(1, $bar->filter('form[action*="/publish"]'), 'the publish button lives in the same region');
        self::assertNull(
            $bar->filter('button[type="submit"]')->attr('disabled'),
            'with drafts pending the button is live',
        );
    }

    /**
     * The regression: once the drafts are published the counter must read zero and the button must
     * be disabled. Re-reading the page is exactly what the grid reload now does for that region.
     */
    public function testAfterPublishingTheCounterIsZeroAndTheButtonIsDisabled(): void
    {
        $this->login();
        $this->draft();
        $this->em->flush();

        $token = $this->plannerBar()->filter('form[action*="/publish"] input[name="_token"]')->attr('value');
        $payload = $this->publish($token);
        self::assertTrue($payload['ok']);

        $bar = $this->plannerBar()->filter('#planner-publish-bar');
        self::assertStringContainsString('0', $bar->text(), 'no drafts remain');
        // A valueless HTML attribute reads back as '' when present, null when absent.
        self::assertNotNull(
            $bar->filter('button[type="submit"]')->attr('disabled'),
            'a button over zero drafts must not invite the "nothing to publish" error',
        );
    }

    /** Publishing twice is what produced the reported error; the second attempt still says so. */
    public function testASecondPublishReportsThereIsNothingLeft(): void
    {
        $this->login();
        $this->draft();
        $this->em->flush();

        $token = $this->plannerBar()->filter('form[action*="/publish"] input[name="_token"]')->attr('value');
        self::assertTrue($this->publish($token)['ok']);

        $second = $this->publish($token);
        self::assertFalse($second['ok']);
        self::assertStringContainsString('no draft shifts to publish', implode(' ', $second['errors']));
    }

    /**
     * A rejected publish names the offending shifts by uuid, so the grid can outline them instead of
     * leaving the manager to match error text against blocks.
     */
    public function testARejectedPublishIdentifiesTheOffendingShifts(): void
    {
        $this->login();
        $good = $this->draft('Fine');
        $bad = $this->draft('   ');   // blank title: fails validation
        $this->em->flush();

        $token = $this->plannerBar()->filter('form[action*="/publish"] input[name="_token"]')->attr('value');
        $payload = $this->publish($token);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($payload['ok']);
        self::assertNotEmpty($payload['errors']);

        self::assertArrayHasKey('invalid', $payload, 'the response must say WHICH shifts are at fault');
        self::assertSame([(string) $bad->getUuid()], $payload['invalid']);
        self::assertNotContains((string) $good->getUuid(), $payload['invalid'], 'a valid shift is not flagged');

        // Publication is atomic: a rejected attempt leaves every draft alone.
        $this->em->clear();
        foreach ($this->em->getRepository(Shift::class)->findAll() as $shift) {
            self::assertSame(ShiftState::DRAFT, $shift->getState());
        }
    }

    /**
     * A shift with no Shift Task must not publish. It used to succeed with a warning shown
     * afterwards, so a manager who painted a shift, hit Publish and read "has no Shift Task set"
     * as something to fix found it already live - the click had published it before the modal
     * even opened.
     */
    public function testAShiftWithoutATaskIsRefusedRatherThanPublishedWithAWarning(): void
    {
        $this->login();

        // Exactly what painting produces: the toolbar's task picker defaults to "None".
        $taskless = (new Shift())->setTitle('Logistics shift')
            ->setStartsAt(new \DateTimeImmutable('+1 day 10:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 12:00'))
            ->setDepartment($this->dept)->setState(ShiftState::DRAFT);
        $this->em->persist($taskless);
        $this->em->flush();

        $token = $this->plannerBar()->filter('form[action*="/publish"] input[name="_token"]')->attr('value');
        $payload = $this->publish($token);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('has no Shift Task set', implode(' ', $payload['errors']));
        self::assertSame([(string) $taskless->getUuid()], $payload['invalid'], 'the grid outlines it');

        $this->em->clear();
        self::assertSame(
            ShiftState::DRAFT,
            $this->em->getRepository(Shift::class)->findAll()[0]->getState(),
            'the shift must still be a draft after a refused publish',
        );
    }

    /** One task-less shift blocks the whole publish, since publication is atomic. */
    public function testOneTasklessShiftBlocksTheWholeBatch(): void
    {
        $this->login();
        $this->draft('Complete');
        $taskless = (new Shift())->setTitle('Incomplete')
            ->setStartsAt(new \DateTimeImmutable('+1 day 14:00'))
            ->setEndsAt(new \DateTimeImmutable('+1 day 16:00'))
            ->setDepartment($this->dept)->setState(ShiftState::DRAFT);
        $this->em->persist($taskless);
        $this->em->flush();

        $token = $this->plannerBar()->filter('form[action*="/publish"] input[name="_token"]')->attr('value');
        self::assertFalse($this->publish($token)['ok']);

        $this->em->clear();
        foreach ($this->em->getRepository(Shift::class)->findAll() as $shift) {
            self::assertSame(ShiftState::DRAFT, $shift->getState(), 'nothing publishes while one shift is incomplete');
        }
    }

    public function testACleanPublishFlagsNothing(): void
    {
        $this->login();
        $this->draft();
        $this->em->flush();

        $token = $this->plannerBar()->filter('form[action*="/publish"] input[name="_token"]')->attr('value');
        $payload = $this->publish($token);

        self::assertTrue($payload['ok']);
        self::assertArrayNotHasKey('invalid', $payload, 'nothing to outline on success');
    }
}
