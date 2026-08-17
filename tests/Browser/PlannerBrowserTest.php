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
 * Stimulus runs a *TargetConnected callback before connect(), so a controller that reads state
 * connect() creates throws on any grid that already holds shifts: its connection aborts and
 * painting, dragging and the grid refresh are all dead behind a 200 and perfectly correct markup.
 * Only the browser console shows it.
 */
final class PlannerBrowserTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    /**
     * The manager's group carries news:view because signing in lands on /news, where a 403 is a
     * severe console error every assertNoConsoleErrors() in this file would report. The grid is
     * seeded with shifts on purpose: an empty one has no block targets and cannot trip the crash
     * these tests guard against.
     */
    private function seed(): Department
    {
        $group = new Group('Managers', 'mgr-'.bin2hex(random_bytes(2)), 'ROLE_STAFF');
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

    /** Rendered blocks are the proof the controller connected: Stimulus removes nothing when it fails. */
    public function testThePlannerLoadsWithoutBrowserErrors(): void
    {
        $dept = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);

        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('.planner-grid', 10);

        self::assertGreaterThan(0, $this->client->getCrawler()->filter('.planner-block')->count());
        $this->assertNoConsoleErrors('the planner page');
    }

    /**
     * Parallel shifts must actually be laid out side by side once the page is rendered and styled.
     * The two 10:00-12:00 shifts share a column, measured from the rendered box, not from the markup.
     */
    public function testParallelShiftsAreLaidOutSideBySide(): void
    {
        $dept = $this->seed();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);

        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('.planner-block', 10);

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
     * Without nowrap the range breaks across lines on a narrow lane and the block's own
     * `overflow: hidden` cuts what is left off: a half-hour shift is 20px tall and shows "10:00-"
     * and nothing more, with the draft badge sitting on top of the remainder. None of that is
     * visible to a test that only renders markup; it takes a laid-out browser to measure.
     *
     * The hard cases are shifts sharing a column two and three ways and half-hour blocks 20px tall,
     * on top of the 2-lane pair and full-width shift seed() already contributes. Lane width is
     * uniform per day, so the full-width case needs a day to itself, and a block with a column of
     * its own has no excuse for hiding half the range.
     */
    public function testShiftTimesOnTheGridAreNeverCutOff(): void
    {
        $dept = $this->seed();
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_EVENT_END, '2026-06-07T00:00:00+00:00');
        $store->set(EventConfigStore::KEY_TEARDOWN_END, '2026-06-08T00:00:00+00:00');

        $task = $this->em->getRepository(ShiftTask::class)->findOneBy(['name' => 'General']);
        foreach ([['10:00', '12:00'], ['18:00', '20:00'], ['18:00', '20:00'], ['21:00', '21:30'], ['21:00', '21:30']] as [$from, $to]) {
            $shift = (new Shift())->setTitle('Parallel '.$from)
                ->setStartsAt(new \DateTimeImmutable('2026-06-01 '.$from, new \DateTimeZone('UTC')))
                ->setEndsAt(new \DateTimeImmutable('2026-06-01 '.$to, new \DateTimeZone('UTC')))
                ->setDepartment($dept)->setShiftTask($task)->setState(ShiftState::DRAFT);
            $this->em->persist($shift);
        }

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

        $wide = array_values(array_filter($blocks, static fn (array $b) => $b['lanes'] === 1));
        self::assertNotEmpty($wide);
        foreach ($wide as $block) {
            self::assertTrue($block['endShown'], 'a full-width block shows the end time, not just the start');
        }

        $this->assertNoConsoleErrors('the planner grid blocks');
    }

    /**
     * A shift needs a task to be saved, so the Add Shift modal offers to create one inline. It has
     * to graft the new task into the picker and select it: sending the manager to the management
     * screen instead would throw away the half-filled form they are standing in.
     *
     * The picker sits inside a modal that is closed on load, so it is present but not visible.
     * Panther's element waits require visibility, hence polling the DOM directly.
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
     * A department running eight things at once must show all eight, in eight columns, with the day
     * widening to fit them: a cap that hides the rest behind an expand link reads as half the day
     * being unplanned. The scroll arrows are the only hint that the grid scrolls, and a manager who
     * does not see them does not scroll.
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

        $this->client->waitFor('.planner-scroll-arrow-next:not([hidden])', 10);
        $this->assertNoConsoleErrors('the planner with a busy day');
    }

    /**
     * The shortest shift the planner can make is half an hour, and it has to be readable. The block
     * is a flex column whose time row never shrinks, so a row height too small for two lines leaves
     * the title a two-pixel sliver of clipped text - the one thing that says which shift this is.
     * Measured from the rendered boxes, because the markup is right either way.
     */
    public function testAHalfHourBlockIsTallEnoughToShowItsTitle(): void
    {
        $dept = $this->seed();
        $shift = (new Shift())->setTitle('Quick sweep')
            ->setStartsAt(new \DateTimeImmutable('2026-06-01 18:00', new \DateTimeZone('UTC')))
            ->setEndsAt(new \DateTimeImmutable('2026-06-01 18:30', new \DateTimeZone('UTC')))
            ->setDepartment($dept)->setState(ShiftState::DRAFT);
        $this->em->persist($shift);
        $this->em->flush();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'planner-mgr@example.com']);
        $this->browse();
        $this->signIn($user, self::PASSWORD);
        $this->client->request('GET', '/manage-shifts/planner?department='.$dept->getUuid());
        $this->client->waitFor('.planner-block', 10);

        $blocks = $this->client->executeScript(
            'return Array.from(document.querySelectorAll(".planner-block")).map((b) => {'
            .'const t = b.querySelector(".planner-block-title");'
            .'return {title: t.textContent.trim(),'
            .' titleHeight: Math.round(t.getBoundingClientRect().height)};'
            .'});'
        );

        $half = array_values(array_filter($blocks, static fn (array $b): bool => $b['title'] === 'Quick sweep'));
        self::assertCount(1, $half, 'the half-hour shift names itself, rather than showing its task or nothing');
        self::assertGreaterThanOrEqual(
            13,
            $half[0]['titleHeight'],
            'the block must leave the title a whole line; the flex row shrinks it to a sliver otherwise',
        );
        $this->assertNoConsoleErrors('the planner grid');
    }

    /**
     * Selecting a shift has to work on the first attempt. A real mouse moves a pixel or two between
     * pressing and releasing, and reading that as a drag makes the planner save a move, replace the
     * grid, and drop the selection on a block that no longer exists, which the manager experiences
     * as having to click several times before anything selects.
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
