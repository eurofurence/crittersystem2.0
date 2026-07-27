<?php

namespace App\Tests\Browser;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\ShiftTask;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Service\EventConfigStore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The planner, in a real browser.
 *
 * The regression this exists for: the planner controller read state in a *TargetConnected callback
 * that it only created in connect(). Stimulus runs those callbacks first, so on any grid that
 * already had shifts the controller threw, its connection aborted, and painting, dragging and the
 * grid refresh were all dead - with a 200 response and perfectly correct markup. Only the browser
 * console showed it.
 */
final class PlannerBrowserTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    private function seed(): Department
    {
        $group = new Group('Managers', 'mgr-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
        foreach (['manageshifts:view', 'shift:manage', 'shift:publish'] as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('planner-mgr')->setEmail('planner-mgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);

        $dept = new Department('Logistics', 'logistics');
        $this->em->persist($dept);

        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_BUILDUP_START, '2026-06-01T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_EVENT_START, '2026-06-01T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_EVENT_END, '2026-06-02T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_TEARDOWN_END, '2026-06-02T00:00:00+00:00');

        // A populated grid is the point: an empty one has no block targets and would not have
        // tripped the crash this test guards.
        $task = new ShiftTask('General');
        $this->em->persist($task);
        foreach ([['10:00', '12:00'], ['10:00', '12:00'], ['14:00', '16:00']] as [$from, $to]) {
            $shift = (new Shift())->setTitle('Shift '.$from)
                ->setStartsAt(new \DateTimeImmutable('2026-06-01 '.$from, new \DateTimeZone('UTC')))
                ->setEndsAt(new \DateTimeImmutable('2026-06-01 '.$to, new \DateTimeZone('UTC')))
                ->setDepartment($dept)->setShiftTask($task)->setState(ShiftState::DRAFT);
            $this->em->persist($shift);
        }

        $this->em->flush();

        return $dept;
    }

    public function testThePlannerLoadsWithoutBrowserErrors(): void
    {
        $dept = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);

        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('.planner-grid', 10);

        // The controller must have connected: Stimulus removes nothing on failure, so the proof is
        // behavioural rather than structural.
        self::assertGreaterThan(0, $this->client->getCrawler()->filter('.planner-block')->count());
        $this->assertNoConsoleErrors('the planner page');
    }

    /** Parallel shifts must actually be laid out side by side once the page is rendered and styled. */
    public function testParallelShiftsAreLaidOutSideBySide(): void
    {
        $dept = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('.planner-block', 10);

        // The two 10:00-12:00 shifts share a column; measured from the rendered box, not the markup.
        $lefts = $this->client->executeScript(
            'return Array.from(document.querySelectorAll(".planner-block"))'
            .'.map((b) => Math.round(b.getBoundingClientRect().left));'
        );

        self::assertGreaterThan(1, \count(array_unique($lefts)), 'overlapping shifts must not stack on the same x');
        $this->assertNoConsoleErrors('the planner grid');
    }

    /** Switching to paint mode must reach the controller and show on the grid. */
    public function testThePaintModeToggleReachesTheController(): void
    {
        $dept = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('#planner-mode-paint', 10);

        self::assertFalse(
            $this->client->executeScript('return document.querySelector(".planner").classList.contains("is-painting");'),
            'select is the default',
        );

        $this->client->executeScript(
            'const el = document.querySelector("#planner-mode-paint");'
            .'el.checked = true; el.dispatchEvent(new Event("change", {bubbles: true}));'
        );
        $this->client->waitFor('.planner.is-painting', 10);

        self::assertTrue(
            $this->client->executeScript('return document.querySelector(".planner").classList.contains("is-painting");'),
        );
        $this->assertNoConsoleErrors('the planner after switching to paint mode');
    }
}
