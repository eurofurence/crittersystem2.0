<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Service\Call\HelpCallService;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The board's Staff and Shifts views, and the call-for-help trigger on them.
 *
 * The trigger is only ever offered to somebody the existing call action will accept: it enforces
 * `call:trigger`, the department scope and its own lead-time window, and a button that fails any of
 * those is worse than no button at all.
 */
final class BoardStaffAndShiftsTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );
    }

    /** @param list<string> $privileges */
    private function login(array $privileges = ['board:view']): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $this->scenario->department));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $user;
    }

    private function url(string $view, int $page = 1): string
    {
        return sprintf(
            '/board/%s/%s?view=%s&page=%d',
            $this->scenario->department->getUuid(),
            (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d'),
            $view,
            $page,
        );
    }

    private function shiftToday(string $title, string $from, string $to, int $needed = 2): Shift
    {
        $day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $shift = $this->scenario->shift($title, 'today 00:00', '+1 hour', $needed);
        $shift->setStartsAt($day->modify($from))->setEndsAt($day->modify($to));
        $this->em->flush();

        return $shift;
    }

    public function testShiftsViewListsTheDaysShifts(): void
    {
        $this->shiftToday('Door', '+6 hours', '+9 hours');
        $this->login();

        $this->client->request('GET', $this->url('shifts'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-board-shifts-table]', 'Door');
    }

    public function testStaffViewListsThePlannedVolunteers(): void
    {
        $shift = $this->shiftToday('Door', '+6 hours', '+9 hours');
        $volunteer = $this->scenario->user();
        $this->scenario->signUp($volunteer, $shift);
        $this->login();

        $this->client->request('GET', $this->url('staff'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-board-staff-cards]', $volunteer->getName());
    }

    public function testShiftsPageTurnsRatherThanScrolling(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_BOARD_PAGE_SIZE_SHIFTS, 2);
        $store->flush();

        foreach (['One', 'Two', 'Three'] as $index => $title) {
            $this->shiftToday($title, sprintf('+%d hours', 6 + $index), sprintf('+%d hours', 8 + $index));
        }
        $this->login();

        $first = $this->client->request('GET', $this->url('shifts'));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $first->filter('[data-board-shifts-table] tbody tr')->count());

        $second = $this->client->request('GET', $this->url('shifts', 2));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $second->filter('[data-board-shifts-table] tbody tr')->count());
    }

    /** A page beyond the end must land on the last page, not on an empty board. */
    public function testAnOutOfRangePageFallsBackToTheLastOne(): void
    {
        $this->shiftToday('Door', '+6 hours', '+9 hours');
        $this->login();

        $crawler = $this->client->request('GET', $this->url('shifts', 99));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-board-shifts-table] tbody tr')->count());
    }

    /** A blank page value is a reason to show the first page, never to answer 400. */
    public function testAMalformedPageValueIsTolerated(): void
    {
        $this->shiftToday('Door', '+6 hours', '+9 hours');
        $this->login();

        $this->client->request('GET', sprintf(
            '/board/%s/%s?view=shifts&page=',
            $this->scenario->department->getUuid(),
            (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d'),
        ));

        self::assertResponseIsSuccessful();
    }

    public function testTheCallButtonIsOfferedForAnImminentUnderstaffedShift(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_CALL_MANAGER_LEAD, 3600);
        $store->flush();

        $day = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $shift = $this->scenario->shift('Door', 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt(new \DateTimeImmutable('+20 minutes'))->setEndsAt(new \DateTimeImmutable('+3 hours'));
        $this->em->flush();

        $this->login(['board:view', 'call:trigger', 'shift:manage']);

        $crawler = $this->client->request('GET', $this->url('shifts'));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('form[action="/calls/trigger"]')->count());
    }

    /**
     * The confirmation waits for the button. Raising a call pages real people, so a dialog that
     * opens itself asks the board to do that once per shift the moment the page loads.
     *
     * Twig prints a false boolean as the empty string and Stimulus reads anything other than '0'
     * and 'false' as true, so the open value has to be printed as an explicit word.
     */
    public function testTheCallConfirmationStaysClosedUntilItIsAskedFor(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_CALL_MANAGER_LEAD, 3600);
        $store->flush();

        $shift = $this->scenario->shift('Door', 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt(new \DateTimeImmutable('+20 minutes'))->setEndsAt(new \DateTimeImmutable('+3 hours'));
        $this->em->flush();

        $this->login(['board:view', 'call:trigger', 'shift:manage']);

        $crawler = $this->client->request('GET', $this->url('shifts'));

        self::assertResponseIsSuccessful();

        $open = $crawler->filter('[data-alert-dialog-open-value]')->extract(['data-alert-dialog-open-value']);
        self::assertNotEmpty($open, 'the shifts view should render at least one call dialog');
        self::assertSame(['false'], array_values(array_unique($open)));
    }

    /** Without call:trigger the action would refuse, so the board must not offer it. */
    public function testTheCallButtonIsWithheldWithoutThePrivilege(): void
    {
        $shift = $this->scenario->shift('Door', 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt(new \DateTimeImmutable('+20 minutes'))->setEndsAt(new \DateTimeImmutable('+3 hours'));
        $this->em->flush();

        $this->login(['board:view']);

        $crawler = $this->client->request('GET', $this->url('shifts'));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('form[action="/calls/trigger"]')->count());
    }

    /** A fully staffed shift has nothing to call for. */
    public function testTheCallButtonIsWithheldWhenTheShiftIsStaffed(): void
    {
        $shift = $this->scenario->shift('Door', 'today 00:00', '+1 hour', 1);
        $shift->setStartsAt(new \DateTimeImmutable('+20 minutes'))->setEndsAt(new \DateTimeImmutable('+3 hours'));
        $this->em->flush();
        $this->scenario->signUp($this->scenario->user(), $shift);

        $this->login(['board:view', 'call:trigger', 'shift:manage']);

        $crawler = $this->client->request('GET', $this->url('shifts'));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('form[action="/calls/trigger"]')->count());
    }

    /** An already-open call is reflected instead of offering a second trigger. */
    public function testAnActiveCallIsShownInsteadOfTheButton(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_CALL_MANAGER_LEAD, 3600);
        $store->flush();

        $shift = $this->scenario->shift('Door', 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt(new \DateTimeImmutable('+20 minutes'))->setEndsAt(new \DateTimeImmutable('+3 hours'));
        $this->em->flush();

        $caller = $this->login(['board:view', 'call:trigger', 'shift:manage']);
        static::getContainer()->get(HelpCallService::class)->trigger($shift, $caller, 2);

        $crawler = $this->client->request('GET', $this->url('shifts'));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('form[action="/calls/trigger"]')->count());
        self::assertSelectorExists('[data-board-call-state]');
    }

    /** Triggering from the board comes back to the board, not to the staffing screen. */
    public function testTriggeringFromTheBoardReturnsToTheBoard(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_CALL_MANAGER_LEAD, 3600);
        $store->flush();

        $shift = $this->scenario->shift('Door', 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt(new \DateTimeImmutable('+20 minutes'))->setEndsAt(new \DateTimeImmutable('+3 hours'));
        $this->em->flush();

        $this->login(['board:view', 'call:trigger', 'shift:manage']);
        $date = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d');

        $crawler = $this->client->request('GET', $this->url('shifts'));
        $this->client->submit($crawler->filter('form[action="/calls/trigger"]')->form());

        self::assertResponseRedirects(sprintf(
            '/board/%s/%s?view=shifts',
            $this->scenario->department->getUuid(),
            $date,
        ));
    }

    /** The return target names a department and a date, never a URL, so it cannot redirect off-site. */
    public function testAnUnusableReturnTargetFallsBackToTheStaffingScreen(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_CALL_MANAGER_LEAD, 3600);
        $store->flush();

        $shift = $this->scenario->shift('Door', 'today 00:00', '+1 hour', 2);
        $shift->setStartsAt(new \DateTimeImmutable('+20 minutes'))->setEndsAt(new \DateTimeImmutable('+3 hours'));
        $this->em->flush();

        $this->login(['board:view', 'call:trigger', 'shift:manage']);

        $crawler = $this->client->request('GET', $this->url('shifts'));
        $form = $crawler->filter('form[action="/calls/trigger"]')->form();
        $form['board_department'] = 'https://example.com/evil';
        $form['board_date'] = 'whenever';

        $this->client->submit($form);

        self::assertResponseRedirects('/manage-shifts/shift/'.$shift->getUuid().'/staffing');
    }
}
