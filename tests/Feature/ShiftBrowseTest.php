<?php

namespace App\Tests\Feature;

use App\Enum\ShiftState;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * /shifts - the volunteer-facing shift browser.
 *
 * Covers the card loop end to end (statuses, availability, sign-up controls, filters) with real
 * shifts in the database; a regression here is invisible to any test that renders the page empty.
 */
final class ShiftBrowseTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    public function testTheListRequiresTheShiftViewPrivilege(): void
    {
        $this->client->loginUser($this->scenario->user(privileges: ['news:view']));
        $this->client->request('GET', '/shifts');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAPublishedShiftIsListedWithItsDetails(): void
    {
        $this->scenario->shift('Morning Briefing', 'tomorrow 09:00');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $crawler = $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('tomorrow'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Morning Briefing');
        self::assertSelectorTextContains('body', 'Demo Location');
        // Grouped under its start hour.
        self::assertStringContainsString('09:00', $crawler->filter('body')->text());
    }

    public function testDraftShiftsAreNotVisibleToVolunteers(): void
    {
        $this->scenario->shift('Secret Draft', 'tomorrow 09:00', state: ShiftState::DRAFT);
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('tomorrow'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Secret Draft');
    }

    public function testAConfirmedMemberSeesTheShiftAsOpenAndCanSignUp(): void
    {
        $this->scenario->shift('Open Shift', 'tomorrow 10:00');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $crawler = $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('tomorrow'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Open');
        self::assertGreaterThan(0, $crawler->filter('form[action*="/signup"]')->count(), 'an eligible volunteer must get a sign-up control');
    }

    public function testANonMemberSeesTheShiftButGetsNoSignUpControl(): void
    {
        $this->scenario->shift('Open Shift', 'tomorrow 10:00');
        $this->client->loginUser($this->scenario->user()); // no confirmed membership

        $crawler = $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('tomorrow'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Open Shift');
        self::assertSame(0, $crawler->filter('form[action*="/signup"]')->count(), 'an ineligible volunteer must not be offered sign-up');
    }

    public function testAShiftTheUserIsSignedUpForShowsAsSignedUpWithACancelControl(): void
    {
        $shift = $this->scenario->shift('My Shift', 'tomorrow 11:00');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->signUp($user, $shift);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('tomorrow'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Signed up');
        self::assertGreaterThan(0, $crawler->filter('form[action*="/cancel"]')->count());
    }

    /**
     * The card's only control is a link to the shift page, upgraded into the dialog by Stimulus.
     * A volunteer with JavaScript off must still reach somewhere useful, so it may never be a
     * bare "#" or a submit button.
     */
    public function testTheViewControlIsALinkToTheShiftPage(): void
    {
        $shift = $this->scenario->shift('Open Shift', 'tomorrow 10:00');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $crawler = $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('tomorrow'))->format('Y-m-d'));
        $view = $crawler->filter('a[data-action="shift-group#trigger"]');

        self::assertGreaterThan(0, $view->count(), 'an eligible volunteer opens the dialog with a working confirm button');
        self::assertSame('/shifts/'.$shift->getUuid(), $view->first()->attr('href'));
    }

    public function testAVolunteerWhoCannotActGetsTheReadOnlyDialog(): void
    {
        $this->scenario->shift('Open Shift', 'tomorrow 10:00');
        $this->client->loginUser($this->scenario->user()); // no confirmed membership

        $crawler = $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('tomorrow'))->format('Y-m-d'));

        self::assertGreaterThan(0, $crawler->filter('a[data-action="shift-group#info"]')->count());
        self::assertSame(0, $crawler->filter('a[data-action="shift-group#trigger"]')->count());
    }

    public function testAFullyStaffedShiftIsShownAsFull(): void
    {
        $shift = $this->scenario->shift('Full Shift', 'tomorrow 12:00', needed: 1);
        $this->scenario->signUp($this->scenario->user(memberOf: $this->scenario->type), $shift);

        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));
        $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('tomorrow'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Full');
    }

    public function testTheAvailableFilterHidesShiftsTheUserCannotJoin(): void
    {
        $this->scenario->shift('Joinable', 'tomorrow 13:00');

        $full = $this->scenario->shift('Already Full', 'tomorrow 14:00', needed: 1);
        $this->scenario->signUp($this->scenario->user(memberOf: $this->scenario->type), $full);

        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));
        $date = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $this->client->request('GET', '/shifts?date='.$date.'&available=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Joinable');
        self::assertSelectorTextNotContains('body', 'Already Full');
    }

    public function testTheLocationFilterNarrowsTheList(): void
    {
        $here = $this->scenario->shift('Here', 'tomorrow 15:00');
        self::assertNotNull($here->getLocation());

        $elsewhere = new \App\Entity\Location('Other Location');
        $this->em->persist($elsewhere);
        $this->em->flush();

        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));
        $date = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        $this->client->request('GET', '/shifts?date='.$date.'&location='.$this->scenario->location->getUuid());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Here');

        // Filtering by a location that has no shifts must not fall back to showing everything.
        $this->client->request('GET', '/shifts?date='.$date.'&location='.$elsewhere->getUuid());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Here');
    }

    public function testSelectingAllInADropdownFilterDoesNotError(): void
    {
        $this->scenario->shift('Any Shift', 'tomorrow 10:00');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        // The "All" option in the location/type dropdowns submits an empty value; the list must
        // treat that as "no filter", not reject the request.
        $date = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $this->client->request('GET', '/shifts?date='.$date.'&location=&type=');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Any Shift');
    }

    public function testShiftsAreGroupedUnderTheirLocalStartHour(): void
    {
        $this->setDisplayTimezone('Asia/Tokyo'); // UTC+9, no DST
        // Stored at 02:00 UTC, which is 11:00 the same day in Tokyo.
        $this->scenario->shift('Tokyo Shift', '2026-08-01 02:00');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $crawler = $this->client->request('GET', '/shifts?date=2026-08-01');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Tokyo Shift', $text);
        // The hour heading must be the local start hour, not the stored UTC hour.
        self::assertStringContainsString('11:00', $text);
        self::assertStringNotContainsString('02:00', $text);
    }

    public function testAShiftIsListedUnderItsLocalCalendarDay(): void
    {
        $this->setDisplayTimezone('Asia/Tokyo');
        // 20:00 UTC on 1 Aug is 05:00 on 2 Aug in Tokyo, so it belongs to the 2nd's tab.
        $this->scenario->shift('Crosses Midnight', '2026-08-01 20:00');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts?date=2026-08-02');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Crosses Midnight');
    }

    private function setDisplayTimezone(string $tz): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_TIMEZONE, $tz);
        $store->flush();
    }

    public function testADayWithNoShiftsRendersTheEmptyState(): void
    {
        $this->scenario->shift('Tomorrow Only', 'tomorrow 10:00');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts?date='.(new \DateTimeImmutable('+8 days'))->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Tomorrow Only');
        self::assertSelectorTextContains('body', 'No shifts');
    }

    public function testTheShiftDetailPageRenders(): void
    {
        $shift = $this->scenario->shift('Detailed Shift', 'tomorrow 16:00');
        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));

        $this->client->request('GET', '/shifts/'.$shift->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Detailed Shift');
    }
}
