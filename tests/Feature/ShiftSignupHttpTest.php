<?php

namespace App\Tests\Feature;

use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Repository\ShiftEntryRepository;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Signing up for and cancelling a shift, driven through HTTP.
 *
 * ShiftSignupService is covered by integration tests; this file covers the controller layer on top
 * of it - the CSRF checks, the ownership check on cancel, and the redirects.
 *
 * Forms are submitted as rendered (the crawler carries the real CSRF token) rather than by minting
 * tokens out of band, so the test exercises the same request a browser would send.
 */
final class ShiftSignupHttpTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function entries(): ShiftEntryRepository
    {
        return static::getContainer()->get(ShiftEntryRepository::class);
    }

    /** The shift list for the day a shift starts on. */
    private function browse(Shift $shift): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('GET', '/shifts?date='.$shift->getStartsAt()->format('Y-m-d'));
    }

    /**
     * The card's sign-up form carrying the role the volunteer picked in the dialog.
     *
     * The card itself holds no role dropdown: the dialog asks, and copies the answer onto this form
     * as `group_type[<shift uuid>]` before submitting it. Posting the bare form the way a browser
     * without the dialog would is a different case, covered separately.
     */
    private function submitCardSignUp(Shift $shift, \Symfony\Component\DomCrawler\Crawler $crawler): void
    {
        $form = $crawler->filter('form[action*="/signup"]')->form();

        $this->client->request('POST', $form->getUri(), $form->getPhpValues() + [
            'group_type' => [(string) $shift->getUuid() => (string) $this->scenario->type->getUuid()],
        ]);
    }

    public function testAConfirmedMemberCanSignUp(): void
    {
        $shift = $this->scenario->shift('Sign Me Up', 'tomorrow 10:00');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->client->loginUser($user);

        $this->submitCardSignUp($shift, $this->browse($shift));

        self::assertResponseRedirects();
        self::assertNotNull($this->entries()->findOneByShiftAndUser($shift, $user), 'the sign-up must be persisted');
    }

    public function testSignUpIsRejectedWithoutAValidCsrfToken(): void
    {
        $shift = $this->scenario->shift('Sign Me Up', 'tomorrow 10:00');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->client->loginUser($user);

        $this->client->request('POST', '/shifts/'.$shift->getUuid().'/signup', [
            '_token' => 'not-a-real-token',
            'volunteer_type' => $this->scenario->type->getUuid(),
        ]);

        self::assertNull($this->entries()->findOneByShiftAndUser($shift, $user), 'a bad CSRF token must never create a sign-up');
    }

    /**
     * A volunteer with no confirmed membership may browse the shift but not join it: no sign-up
     * control is offered, and posting to the endpoint anyway still creates nothing.
     */
    public function testAnUnconfirmedMemberIsOfferedNoSignUpAndCannotForceOne(): void
    {
        $shift = $this->scenario->shift('Members Only', 'tomorrow 10:00');
        $user = $this->scenario->user();
        $this->client->loginUser($user);

        $crawler = $this->browse($shift);
        self::assertSame(0, $crawler->filter('form[action*="/signup"]')->count());

        $this->client->request('POST', '/shifts/'.$shift->getUuid().'/signup', [
            '_token' => 'not-a-real-token',
            'volunteer_type' => $this->scenario->type->getUuid(),
        ]);

        self::assertNull($this->entries()->findOneByShiftAndUser($shift, $user));
    }

    /** Replaying the same sign-up request is idempotent: the volunteer is booked once, not twice. */
    public function testSigningUpTwiceDoesNotCreateASecondEntry(): void
    {
        $shift = $this->scenario->shift('Once Only', 'tomorrow 10:00');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->client->loginUser($user);

        $crawler = $this->browse($shift);
        $this->submitCardSignUp($shift, $crawler);
        $this->submitCardSignUp($shift, $crawler);

        self::assertCount(
            1,
            $this->em->getRepository(ShiftEntry::class)->findBy(['shift' => $shift, 'user' => $user]),
            'replaying the sign-up must not book the volunteer twice',
        );
    }

    public function testAVolunteerCanCancelTheirOwnSignUp(): void
    {
        $shift = $this->scenario->shift('Cancel Me', 'tomorrow 10:00');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->signUp($user, $shift);
        $this->client->loginUser($user);

        $crawler = $this->browse($shift);
        $this->client->submit($crawler->filter('form[action*="/cancel"]')->form());

        self::assertResponseRedirects();
        self::assertNull($this->entries()->findOneByShiftAndUser($shift, $user), 'the sign-up must be gone');
    }

    /**
     * The cancel action checks ownership BEFORE CSRF, so this asserts the ownership check itself
     * rather than letting CSRF quietly cover for it: another volunteer replaying the entry URL must
     * get a 403, and the owner must still be on the shift.
     */
    public function testAVolunteerCannotCancelSomeoneElsesSignUp(): void
    {
        $shift = $this->scenario->shift('Not Yours', 'tomorrow 10:00');
        $owner = $this->scenario->user(memberOf: $this->scenario->type);
        $entry = $this->scenario->signUp($owner, $shift);

        $this->client->loginUser($this->scenario->user(memberOf: $this->scenario->type));
        $this->client->request('POST', '/shifts/entries/'.$entry->getUuid().'/cancel', ['_token' => 'anything']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(
            $this->entries()->findOneByShiftAndUser($shift, $owner),
            "another volunteer's sign-up must survive: cancelling it is not theirs to do",
        );
    }

    public function testCancelIsRejectedWithoutAValidCsrfToken(): void
    {
        $shift = $this->scenario->shift('Keep Me', 'tomorrow 10:00');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $entry = $this->scenario->signUp($user, $shift);
        $this->client->loginUser($user);

        $this->client->request('POST', '/shifts/entries/'.$entry->getUuid().'/cancel', ['_token' => 'not-a-real-token']);

        self::assertNotNull($this->entries()->findOneByShiftAndUser($shift, $user), 'a bad CSRF token must never cancel a sign-up');
    }

    public function testMyShiftsListsTheUsersOwnSignUpsOnly(): void
    {
        $mine = $this->scenario->shift('My Own Shift', 'tomorrow 10:00');
        $theirs = $this->scenario->shift('Someone Elses Shift', 'tomorrow 14:00');

        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->scenario->signUp($user, $mine);
        $this->scenario->signUp($this->scenario->user(memberOf: $this->scenario->type), $theirs);

        $this->client->loginUser($user);
        $this->client->request('GET', '/my-shifts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'My Own Shift');
        self::assertSelectorTextNotContains('body', 'Someone Elses Shift');
    }

    public function testSignUpIsRefusedOnceTheShiftHasEnded(): void
    {
        $past = $this->scenario->shift('Already Over', '-4 hours', '+1 hour');
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->client->loginUser($user);

        $this->client->request('POST', '/shifts/'.$past->getUuid().'/signup', [
            '_token' => 'not-a-real-token',
            'volunteer_type' => $this->scenario->type->getUuid(),
        ]);

        self::assertNull($this->entries()->findOneByShiftAndUser($past, $user), 'a past shift must not accept sign-ups');
    }

    /** An anonymous caller holding a guessed entry uuid is bounced to login, and the entry survives. */
    public function testAnEntryOfAnotherUserIsNotEvenReachableByUuidGuessing(): void
    {
        $shift = $this->scenario->shift('Private', 'tomorrow 10:00');
        $owner = $this->scenario->user(memberOf: $this->scenario->type);
        $entry = $this->scenario->signUp($owner, $shift);

        $this->client->request('POST', '/shifts/entries/'.$entry->getUuid().'/cancel', ['_token' => 'x']);

        self::assertResponseRedirects('/login');
        self::assertInstanceOf(User::class, $this->entries()->findOneByShiftAndUser($shift, $owner)?->getUser());
    }
}
