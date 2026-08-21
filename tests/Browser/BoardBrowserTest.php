<?php

namespace App\Tests\Browser;

use App\Entity\DutyRecord;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The board in a real browser.
 *
 * Two things about this page cannot be checked by rendering markup. It runs a charting library and
 * two Stimulus controllers, and an exception in any of them leaves a perfectly valid 200 with a
 * silently broken panel. And it is a wall display whose whole layout promise is that it never
 * scrolls - which is a property of the rendered box model, not of the HTML.
 */
final class BoardBrowserTest extends BrowserTestCase
{
    private const PASSWORD = 'secret123';

    private ShiftScenario $scenario;

    /**
     * Volunteers are checked in an hour ago rather than at their shift's start: presence is an open
     * span from the check-in, so anchoring it to the shift leaves nobody present when the suite runs
     * early in the morning, the "show all" link never renders, and the dialog test fails on the clock
     * instead of on the code. One volunteer is on duty without a shift so the presence union is
     * exercised as well.
     *
     * The manager's group carries news:view because signing in with no target lands on the news
     * index, where a group without it meets a 403 on a page nobody chose.
     */
    private function seed(): User
    {
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );

        $day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $titles = ['Door', 'Roaming', 'Build-up', 'Cloakroom', 'Runner', 'Stage left'];
        foreach ($titles as $index => $title) {
            /** @var Shift $shift */
            $shift = $this->scenario->shift($title, 'today 00:00', '+1 hour', 3);
            $shift->setStartsAt($day->modify(sprintf('+%d hours', 6 + $index)))
                ->setEndsAt($day->modify(sprintf('+%d hours', 9 + $index)));

            for ($v = 0; $v < 2; ++$v) {
                $volunteer = $this->scenario->user();
                $entry = $this->scenario->signUp($volunteer, $shift);
                $entry->checkIn(new \DateTimeImmutable('-1 hour'));
            }
        }

        $roamer = $this->scenario->user();
        $this->em->persist(new DutyRecord($roamer, $this->scenario->department));

        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        foreach (['board:view', 'manageshifts:view', 'news:view'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $manager = new User();
        $manager->setName('boardmgr')->setEmail('boardmgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $manager->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($manager, self::PASSWORD));
        $manager->completeOnboarding();
        $this->em->persist($manager->assignGroup($group, $this->scenario->department));
        $this->em->persist($manager);
        $this->em->flush();

        return $manager;
    }

    private function boardUrl(): string
    {
        return '/board/'.$this->scenario->department->getUuid().'/'
            .(new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    public function testOverviewRendersWithoutScrollingOrConsoleErrors(): void
    {
        $manager = $this->seed();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));
        $this->signIn($manager, self::PASSWORD);

        $client->request('GET', $this->boardUrl());
        $client->waitFor('[data-board-kpis]', 10);

        self::assertSelectorExists('[data-board-forecast]');
        self::assertSelectorExists('[data-board-active-grid]');

        $overflow = $client->executeScript(
            'return [document.documentElement.scrollWidth - document.documentElement.clientWidth,'
            .' document.documentElement.scrollHeight - document.documentElement.clientHeight];'
        );
        self::assertLessThanOrEqual(1, $overflow[0], 'the board must never scroll horizontally');
        self::assertLessThanOrEqual(1, $overflow[1], 'the board must never scroll vertically');

        $this->assertNoConsoleErrors('the board overview');
    }

    /**
     * The workload bar is server-rendered markup, so it cannot fail to draw the way the charting
     * library it replaced could. What is still worth asserting is that its segments add up: a bar
     * whose widths do not reach 100% is a silently wrong picture of the department.
     */
    public function testTheWorkloadBarCoversItsWholePopulation(): void
    {
        $manager = $this->seed();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));
        $this->signIn($manager, self::PASSWORD);

        $client->request('GET', $this->boardUrl());
        $client->waitFor('[data-slot="card"]', 10);

        $total = $client->executeScript(
            'const track = document.querySelector("[data-board-workload]");'
            .' if (!track) { return -1; }'
            .' return [...track.children].reduce((sum, seg) => sum + parseFloat(seg.style.width || 0), 0);'
        );

        self::assertEqualsWithDelta(100.0, (float) $total, 0.5, 'the workload bar must account for everyone');
        $this->assertNoConsoleErrors('the workload panel');
    }

    /**
     * The dialog is a Turbo Frame outside the live region. If the frame ids ever stop matching,
     * Turbo drops the response and the link does nothing at all.
     *
     * Closing has to empty the frame rather than merely hide the dialog: the next open re-fetches,
     * so a dialog left in the DOM shows numbers that went stale while it was shut. That emptying is
     * polled for instead of waited on with a selector: the dialog fades out, so it stops being
     * visible slightly before the handler clears the frame, and a selector-based wait races one
     * side or the other of that gap.
     */
    public function testShowAllOpensAndClosesTheDialog(): void
    {
        $manager = $this->seed();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));
        $this->signIn($manager, self::PASSWORD);

        $client->request('GET', $this->boardUrl());
        $client->waitFor('[data-board-kpis]', 10);

        $client->executeScript("document.querySelector('a[data-turbo-frame=\"board-modal\"]').click();");
        $client->waitFor('[data-slot="dialog-content"][open]', 10);

        $client->executeScript("document.querySelector('[data-slot=\"dialog-content\"] button[data-action*=\"dialog#close\"]').click();");
        $remaining = null;
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $remaining = (int) $client->executeScript(
                "return document.querySelector('#board-modal').innerHTML.trim().length;"
            );
            if ($remaining === 0) {
                break;
            }
            usleep(100_000);
        }

        self::assertSame(0, $remaining, 'the dialog frame must be emptied on close');

        $this->assertNoConsoleErrors('the board dialog');
    }

    /** The other two views are height-constrained the same way and page rather than scroll. */
    public function testStaffAndShiftsViewsAlsoFitTheScreen(): void
    {
        $manager = $this->seed();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));
        $this->signIn($manager, self::PASSWORD);

        foreach (['staff' => '[data-board-staff-cards]', 'shifts' => '[data-board-shifts-table]'] as $view => $selector) {
            $client->request('GET', $this->boardUrl().'?view='.$view);
            $client->waitFor($selector, 10);

            $overflow = $client->executeScript(
                'return [document.documentElement.scrollWidth - document.documentElement.clientWidth,'
                .' document.documentElement.scrollHeight - document.documentElement.clientHeight];'
            );
            self::assertLessThanOrEqual(1, $overflow[0], $view.' must never scroll horizontally');
            self::assertLessThanOrEqual(1, $overflow[1], $view.' must never scroll vertically');
        }

        $this->assertNoConsoleErrors('the staff and shifts views');
    }

    /*
     * The board keeps itself current without a heartbeat: it subscribes to its department and also
     * declares the next instant its content changes on its own. If either attribute stops being
     * emitted the board still renders perfectly and quietly stops updating, which is the one failure
     * a wall display cannot report for itself.
     */
    public function testTheViewIsWiredAsALiveRegionWithItsNextTransition(): void
    {
        $manager = $this->seed();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));
        $this->signIn($manager, self::PASSWORD);

        $client->request('GET', $this->boardUrl());
        $client->waitFor('[data-board-kpis]', 10);

        $wiring = $client->executeScript(
            'const view = document.querySelector("[data-board-view]");'
            .' return [view?.dataset.controller ?? "", view?.dataset.liveStreamUrlValue ?? "",'
            .' view?.querySelector("[data-next-transition]")?.dataset.nextTransition ?? ""];'
        );

        self::assertStringContainsString('live-stream', $wiring[0]);
        self::assertStringContainsString('/view', $wiring[1], 'the region must re-fetch the fragment, not the page');
        self::assertNotSame('', $wiring[2], 'the fragment must carry the moment it next changes');

        $this->assertNoConsoleErrors('the board live region');
    }

    /*
     * Raising a call for help notifies real people, so it sits behind a confirmation. The board does
     * not load Bootstrap, which is what the application's shared `confirm` controller needs, and its
     * fallback is a native dialog this project does not allow - so this is the kit's AlertDialog, and
     * a test that only rendered markup could not tell whether it actually opens. The form inside the
     * dialog is what posts, so without it the button confirms nothing.
     */
    public function testTheCallForHelpConfirmationOpens(): void
    {
        $manager = $this->seedCallableShift();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));
        $this->signIn($manager, self::PASSWORD);

        $client->request('GET', $this->boardUrl().'?view=shifts');
        $client->waitFor('[data-board-shifts-table]', 10);

        $trigger = $client->executeScript(
            "const b = document.querySelector('button[data-action*=\"alert-dialog#open\"]');"
            .' if (!b) { return false; } b.click(); return true;'
        );
        self::assertTrue($trigger, 'the board must offer a call-for-help trigger on an imminent short shift');
        $client->waitFor('[data-slot="alert-dialog-content"][open]', 10);

        self::assertSelectorExists('[data-slot="alert-dialog-content"] form[action="/calls/trigger"]');
        $this->assertNoConsoleErrors('the call-for-help confirmation');
    }

    /*
     * Confirming has to raise the call. This is the half a markup test cannot reach: the dialog can
     * open, hold the right form and still do nothing, because the kit's Button is a `type="button"`
     * unless the caller says otherwise and a plain button inside a form submits nothing. Everything
     * on screen looks like it worked and nobody is paged, so the assertion is on the stored call
     * rather than on anything the page shows.
     */
    public function testConfirmingTheCallForHelpRaisesIt(): void
    {
        $manager = $this->seedCallableShift();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));
        $this->signIn($manager, self::PASSWORD);

        $client->request('GET', $this->boardUrl().'?view=shifts');
        $client->waitFor('[data-board-shifts-table]', 10);

        $client->executeScript(
            "document.querySelector('button[data-action*=\"alert-dialog#open\"]').click();"
        );
        $client->waitFor('[data-slot="alert-dialog-content"][open]', 10);

        $client->executeScript(
            "document.querySelector('[data-slot=\"alert-dialog-content\"] form[action=\"/calls/trigger\"]"
            ." button[type=\"submit\"]').click();"
        );
        $client->waitFor('[data-board-call-state]', 10);

        self::assertNotEmpty(
            $this->em->getRepository(\App\Entity\HelpCall::class)->findAll(),
            'confirming the dialog must actually raise the call',
        );
        $this->assertNoConsoleErrors('raising a call for help');
    }

    /*
     * Marking a volunteer absent from the board, end to end.
     *
     * Three things here exist only in a browser. The roster arrives as Turbo Frame content and opens
     * itself; its confirmation is a second `<dialog>` opened on top of the first, which is where
     * stacking would break; and the roster's own click-outside handler must not treat a click in the
     * confirmation as a click on its backdrop and shut the roster underneath it.
     */
    public function testAVolunteerCanBeMarkedAbsentFromTheBoardRoster(): void
    {
        $manager = $this->seed();

        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => 'shift:manage']) ?? new Privilege('shift:manage');
        $this->em->persist($privilege);
        $manager->getActiveAssignments()[0]->getGroup()->addPrivilege($privilege);
        $this->em->flush();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));
        $this->signIn($manager, self::PASSWORD);

        $client->request('GET', $this->boardUrl().'?view=shifts');
        $client->waitFor('[data-board-shifts-table]', 10);

        $client->executeScript("document.querySelector('[data-board-roster-link]').click();");
        $client->waitFor('#dialog-board-roster[open]', 10);

        $client->executeScript(
            "document.querySelector('#dialog-board-roster button[data-action*=\"alert-dialog#open\"]').click();"
        );
        $client->waitFor('[data-slot="alert-dialog-content"][open]', 10);

        self::assertTrue(
            $client->executeScript("return document.querySelector('#dialog-board-roster').open;"),
            'the roster must stay open underneath its own confirmation',
        );

        $client->executeScript(
            "document.querySelector('[data-slot=\"alert-dialog-content\"] form[action*=\"/noshow\"]"
            ." button[type=\"submit\"]').click();"
        );
        $client->waitForInvisibility('#dialog-board-roster', 10);

        $this->em->clear();
        $noshows = array_filter(
            $this->em->getRepository(\App\Entity\ShiftEntry::class)->findAll(),
            static fn (\App\Entity\ShiftEntry $entry): bool => $entry->isNoshow(),
        );
        self::assertCount(1, $noshows, 'confirming the dialog must actually record the no-show');

        $this->assertNoConsoleErrors('marking a no-show from the board');
    }

    /** A shift starting shortly and short of people, which is when the call button is offered. */
    private function seedCallableShift(): User
    {
        $store = static::getContainer()->get(\App\Service\EventConfigStore::class);
        $store->set(\App\Service\EventConfigStore::KEY_CALL_MANAGER_LEAD, 3600);
        $store->flush();

        $manager = $this->seed();

        /** @var Shift $shift */
        $shift = $this->scenario->shift('Late gate', 'today 00:00', '+1 hour', 3);
        $shift->setStartsAt(new \DateTimeImmutable('+20 minutes'))->setEndsAt(new \DateTimeImmutable('+3 hours'));

        foreach (['call:trigger', 'shift:manage'] as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $manager->getActiveAssignments()[0]->getGroup()->addPrivilege($privilege);
        }
        $this->em->flush();

        return $manager;
    }

    /*
     * The department rail is a combobox, which is three behaviours a rendered-markup test cannot
     * see: it opens, it filters, and picking an entry navigates. The last one matters most - the
     * component is a form control that only announces a change, and the board has no form to submit,
     * so without the controller listening for that announcement the rail looks fine and does nothing.
     */
    public function testTheDepartmentComboboxFiltersAndNavigates(): void
    {
        $manager = $this->seed();

        $other = new \App\Entity\Department('Security', 'security-'.bin2hex(random_bytes(3)));
        $this->em->persist($other);
        $this->em->persist($manager->assignGroup($manager->getActiveAssignments()[0]->getGroup(), $other));
        $this->em->flush();

        $client = $this->browse();
        $client->getWebDriver()->manage()->window()->setSize(new \Facebook\WebDriver\WebDriverDimension(1920, 1080));
        $this->signIn($manager, self::PASSWORD);

        $client->request('GET', $this->boardUrl());
        $client->waitFor('[data-board-rail="departments"]', 10);

        $client->executeScript("document.querySelector('[data-slot=\"combobox-trigger\"]').click();");
        $client->waitForVisibility('[data-slot="combobox-content"]', 10);

        $client->executeScript(
            "const s = document.querySelector('[data-slot=\"combobox-content\"] input[role=\"searchbox\"]');"
            .' s.value = "Secur"; s.dispatchEvent(new Event("input", { bubbles: true }));'
        );

        $visible = $client->executeScript(
            "return [...document.querySelectorAll('[data-slot=\"combobox-item\"]')]"
            .'.filter(o => o.offsetParent !== null).map(o => o.dataset.label);'
        );
        self::assertSame(['Security'], $visible, 'the filter must narrow the list to the match');

        $client->executeScript(
            "[...document.querySelectorAll('[data-slot=\"combobox-item\"]')]"
            .'.find(o => o.dataset.label === "Security").click();'
        );

        $client->waitForElementToContain('h1', 'Security', 10);
        self::assertStringContainsString((string) $other->getUuid(), $client->getCurrentURL());

        $this->assertNoConsoleErrors('the department combobox');
    }
}
