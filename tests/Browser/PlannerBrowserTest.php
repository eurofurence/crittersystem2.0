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
        // news:view is part of it because signing in lands on /news, and a 403 there is a severe
        // console error that every assertNoConsoleErrors() in this file would report.
        foreach (['manageshifts:view', 'shift:manage', 'shift:publish', 'news:view'] as $p) {
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

    /**
     * The shift times on a grid block must actually be readable.
     *
     * They were not: the range had no nowrap, so on a narrow lane it broke across lines that the
     * block's own `overflow: hidden` then cut off - a half-hour shift is 20px tall and showed
     * "10:00-" and nothing more - while the draft badge sat on top of what was left. None of that is
     * visible to a test that only renders markup; it takes a laid-out browser to measure.
     */
    public function testShiftTimesOnTheGridAreNeverCutOff(): void
    {
        $dept = $this->seed();
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_EVENT_END, '2026-06-07T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_TEARDOWN_END, '2026-06-08T00:00:00+00:00');

        // The hard cases: shifts sharing a column two and three ways, and half-hour blocks that are
        // only 20px tall. seed() already contributes a 2-lane pair and a full-width shift.
        $task = $this->em->getRepository(ShiftTask::class)->findOneBy(['name' => 'General']);
        foreach ([['10:00', '12:00'], ['18:00', '20:00'], ['18:00', '20:00'], ['21:00', '21:30'], ['21:00', '21:30']] as [$from, $to]) {
            $shift = (new Shift())->setTitle('Parallel '.$from)
                ->setStartsAt(new \DateTimeImmutable('2026-06-01 '.$from, new \DateTimeZone('UTC')))
                ->setEndsAt(new \DateTimeImmutable('2026-06-01 '.$to, new \DateTimeZone('UTC')))
                ->setDepartment($dept)->setShiftTask($task)->setState(ShiftState::DRAFT);
            $this->em->persist($shift);
        }

        // Lane width is now uniform per day, so the full-width case needs a day of its own.
        $lone = (new Shift())->setTitle('Alone')
            ->setStartsAt(new \DateTimeImmutable('2026-06-02 09:00', new \DateTimeZone('UTC')))
            ->setEndsAt(new \DateTimeImmutable('2026-06-02 11:00', new \DateTimeZone('UTC')))
            ->setDepartment($dept)->setShiftTask($task)->setState(ShiftState::DRAFT);
        $this->em->persist($lone);
        $this->em->flush();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);
        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('.planner-block', 10);

        $blocks = $this->client->executeScript(
            'return Array.from(document.querySelectorAll(".planner-block")).map((b) => {'
            .'const box = b.getBoundingClientRect();'
            .'const time = b.querySelector(".planner-block-time").getBoundingClientRect();'
            .'const end = b.querySelector(".planner-block-to");'
            .'return {lanes: Number(b.dataset.lanes), width: Math.round(box.width),'
            .' overflowsX: time.right > box.right - 3, overflowsY: time.bottom > box.bottom - 1,'
            .' endShown: getComputedStyle(end).display !== "none"};'
            .'});'
        );

        self::assertGreaterThanOrEqual(3, \count($blocks), 'the fixture must produce shared columns');

        foreach ($blocks as $block) {
            self::assertFalse($block['overflowsX'], \sprintf('a %d-lane block cuts its time off sideways', $block['lanes']));
            self::assertFalse($block['overflowsY'], \sprintf('a %d-lane block cuts its time off below', $block['lanes']));
        }

        // A block with a column to itself has no excuse for hiding half the range.
        $wide = array_values(array_filter($blocks, static fn (array $b) => $b['lanes'] === 1));
        self::assertNotEmpty($wide);
        foreach ($wide as $block) {
            self::assertTrue($block['endShown'], 'a full-width block shows the end time, not just the start');
        }

        $this->assertNoConsoleErrors('the planner grid blocks');
    }

    /**
     * A shift now needs a task to be saved, so the Add Shift modal offers to create one inline. It
     * has to graft the new task into the picker and select it: sending the manager to the management
     * screen instead would throw away the half-filled form they are standing in.
     */
    public function testANewShiftTaskIsCreatedFromTheAddShiftModalAndSelected(): void
    {
        $dept = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('#add-task', 10);

        $this->client->executeScript(
            'document.querySelector(\'[data-action="shift-task-create#open"]\').click();'
        );
        $this->client->executeScript(
            'const el = document.querySelector(\'[data-shift-task-create-target="name"]\');'
            .'el.value = "Gate briefing";'
        );
        $this->client->executeScript(
            'document.querySelector(\'[data-action="shift-task-create#create"]\').click();'
        );

        // The picker lives inside a modal that is closed on load, so it is present but not visible;
        // Panther's element waits require visibility, hence polling the DOM directly.
        $options = [];
        for ($i = 0; $i < 40; ++$i) {
            $options = $this->client->executeScript(
                'return Array.from(document.querySelectorAll("#add-task option")).map((o) => o.textContent.trim());'
            );
            if (\in_array('Gate briefing', $options, true)) {
                break;
            }
            usleep(250_000);
        }

        self::assertContains('Gate briefing', $options, 'the new task is grafted into the picker');
        self::assertSame(
            'Gate briefing',
            $this->client->executeScript(
                'const s = document.querySelector("#add-task");'
                .'return s.options[s.selectedIndex].textContent.trim();'
            ),
            'the new task is selected, so the form the manager is filling stays usable',
        );
        $this->assertNoConsoleErrors('the planner after creating a shift task inline');
    }

    /**
     * A department running eight things at once must show all eight. They used to be capped at four
     * with the rest behind an "expand this day" link, which read as half the day being unplanned.
     */
    public function testEveryParallelShiftIsVisibleAndTheColumnWidensToFitThem(): void
    {
        $dept = $this->seed();
        $task = $this->em->getRepository(ShiftTask::class)->findOneBy(['name' => 'General']);
        for ($i = 0; $i < 8; ++$i) {
            $shift = (new Shift())->setTitle('Parallel '.$i)
                ->setStartsAt(new \DateTimeImmutable('2026-06-01 18:00', new \DateTimeZone('UTC')))
                ->setEndsAt(new \DateTimeImmutable('2026-06-01 20:00', new \DateTimeZone('UTC')))
                ->setDepartment($dept)->setShiftTask($task)->setState(ShiftState::DRAFT);
            $this->em->persist($shift);
        }
        $this->em->flush();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);
        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('.planner-block', 10);

        $measured = $this->client->executeScript(
            'const blocks = Array.from(document.querySelectorAll(".planner-block"))'
            .'.filter((b) => b.textContent.includes("18:00"));'
            .'const day = document.querySelector(".planner-day");'
            .'return {count: blocks.length,'
            .' lefts: new Set(blocks.map((b) => Math.round(b.getBoundingClientRect().left))).size,'
            .' narrowest: Math.min(...blocks.map((b) => b.getBoundingClientRect().width)),'
            .' dayWidth: Math.round(day.getBoundingClientRect().width),'
            .' overflows: document.querySelector(".planner-grid").scrollWidth'
            .'   > document.querySelector(".planner-grid").clientWidth};'
        );

        self::assertSame(8, $measured['count'], 'every parallel shift renders');
        self::assertSame(8, $measured['lefts'], 'and each one has a column of its own');
        self::assertGreaterThan(60, $measured['narrowest'], 'a lane stays wide enough to read');
        self::assertGreaterThan(1000, $measured['dayWidth'], 'the day column widened rather than hiding shifts');
        self::assertTrue($measured['overflows'], 'the grid is now wider than its viewport');

        // The arrows are the only hint that the grid scrolls; a manager who does not see them does
        // not scroll.
        $this->client->waitFor('.planner-scroll-arrow-next:not([hidden])', 10);
        $this->assertNoConsoleErrors('the planner with a busy day');
    }

    /**
     * Selecting a shift has to work on the first attempt. A real mouse moves a pixel or two between
     * pressing and releasing, which used to be read as a drag: the planner saved a move, replaced
     * the grid, and the selection landed on a block that no longer existed. Managers described it as
     * having to click several times before anything selected.
     */
    public function testAClickThatWobblesSelectsTheShiftInsteadOfMovingIt(): void
    {
        $dept = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('.planner-block', 10);

        $before = $this->client->executeScript(
            'return document.querySelector(".planner-block").style.top;'
        );

        $block = $this->client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('.planner-block'));
        $this->client->getWebDriver()->action()
            ->moveToElement($block)
            ->clickAndHold()
            ->moveByOffset(2, 2)
            ->release()
            ->perform();

        $this->client->waitFor('.planner-block.is-selected', 10);

        self::assertSame(
            $before,
            $this->client->executeScript('return document.querySelector(".planner-block").style.top;'),
            'the shift must not have moved',
        );
        $this->assertNoConsoleErrors('the planner after selecting a shift');
    }
}
