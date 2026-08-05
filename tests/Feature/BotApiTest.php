<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The /api/bot surface: service-token authentication, acting-user resolution, and
 * per-endpoint authorization against the acting volunteer.
 */
final class BotApiTest extends DatabaseWebTestCase
{
    private const TOKEN = 'test-bot-token';

    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario(
            $this->em,
            static::getContainer()->get(UserPasswordHasherInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $uri, ?User $actor = null, ?string $token = self::TOKEN, array $body = []): void
    {
        $server = [];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if ($actor !== null) {
            $server['HTTP_X_ACTING_USER'] = (string) $actor->getUuid();
            $server['HTTP_X_ACTING_TOKEN'] = (string) $actor->getTelegramActingToken();
        }
        $server['CONTENT_TYPE'] = 'application/json';

        $this->client->request($method, $uri, [], [], $server, $body === [] ? null : json_encode($body));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * A user holding $privileges through a group assignment scoped to $department.
     * Scoping is what the manager endpoints must honour, so it cannot be faked with
     * an unscoped group.
     *
     * @param string[] $privileges
     */
    private function scopedManager(array $privileges, Department $department): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Mgr '.$suffix, 'mgr-'.$suffix, 'ROLE_USER');
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('mgr-'.$suffix)->setEmail('mgr-'.$suffix.'@example.com')->setPassword('x')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->completeOnboarding();
        $user->linkTelegram('tg-mgr-'.$suffix, '@mgr-'.$suffix);
        // assignGroup(), not a hand-built UserGroupAssignment: it adds to the
        // user's own collection, which is what the voter reads.
        $this->em->persist($user->assignGroup($group, $department));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    // --- authentication -------------------------------------------------

    public function testRequestWithoutTokenIsRejected(): void
    {
        $this->request('GET', '/api/bot/shifts', $this->scenario->user(), null);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testRequestWithWrongTokenIsRejected(): void
    {
        $this->request('GET', '/api/bot/shifts', $this->scenario->user(), 'not-the-token');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The bot's own identity carries no privileges: without an acting user there is
     * nobody to authorize, and the request must not fall back to the token's identity.
     */
    public function testValidTokenWithoutActingUserIsRejected(): void
    {
        $this->request('GET', '/api/bot/shifts');

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testBannedActingUserIsRejected(): void
    {
        $user = $this->scenario->user();
        static::getContainer()->get(\App\Gdpr\BanChecker::class)->ban($user);
        $this->em->flush();

        $this->request('GET', '/api/bot/shifts', $user);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Unlinking in the web UI must sever the bot's access immediately. Before the
     * resolver checked link state, the account still resolved by uuid and the bot
     * kept reading data - including minting a fresh digital-ID token.
     */
    public function testUnlinkedActingUserIsRejectedIncludingDigitalId(): void
    {
        $user = $this->scenario->user();
        $user->unlinkTelegram();
        $this->em->flush();

        $this->request('GET', '/api/bot/shifts', $user);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('acting_user_not_linked', $this->json()['error']);

        // The digital badge is the sharpest example: a revoked link must not be
        // able to generate a valid identity token.
        $this->request('POST', '/api/bot/digital-id/token', $user);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The acting token is the revocable credential: a token captured before an
     * unlink must not work afterwards, and a re-link must mint a different one -
     * so the old token stays dead even once the uuid is linked again.
     */
    public function testStaleActingTokenIsRejectedAcrossRelink(): void
    {
        $user = $this->scenario->user();
        $staleToken = $user->getTelegramActingToken();
        self::assertNotNull($staleToken);

        // Unlink, then re-link (as would happen from a new link code).
        $user->unlinkTelegram();
        $this->em->flush();
        $user->linkTelegram('999888', '@again');
        $this->em->flush();

        self::assertNotSame($staleToken, $user->getTelegramActingToken(), 're-link rotates the token');

        // The uuid is linked again, but the OLD token must not authorize.
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            'HTTP_X_ACTING_USER' => (string) $user->getUuid(),
            'HTTP_X_ACTING_TOKEN' => (string) $staleToken,
            'CONTENT_TYPE' => 'application/json',
        ];
        $this->client->request('GET', '/api/bot/shifts', [], [], $server);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('acting_user_not_linked', $this->json()['error']);

        // The fresh token works.
        $this->request('GET', '/api/bot/shifts', $user);
        self::assertResponseIsSuccessful();
    }

    // --- shifts ---------------------------------------------------------

    public function testShiftListRequiresShiftViewPrivilege(): void
    {
        $this->scenario->shift('Morning Gate');

        $this->request('GET', '/api/bot/shifts', $this->scenario->user([]));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testShiftListReturnsUuidsAndDerivedStatus(): void
    {
        $shift = $this->scenario->shift('Morning Gate');

        $this->request('GET', '/api/bot/shifts', $this->scenario->user());

        self::assertResponseIsSuccessful();
        $shifts = $this->json()['shifts'];
        self::assertCount(1, $shifts);
        self::assertSame((string) $shift->getUuid(), $shifts[0]['id']);
        self::assertSame('empty', $shifts[0]['status']);
        self::assertSame(2, $shifts[0]['total_slots']);
    }

    /** Draft shifts are unpublished; the bot must never see them. */
    public function testDraftShiftsAreNotListed(): void
    {
        $this->scenario->shift('Secret Plan', 'tomorrow 10:00', '+2 hours', 2, \App\Enum\ShiftState::DRAFT);

        $this->request('GET', '/api/bot/shifts', $this->scenario->user());

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['shifts']);
    }

    /**
     * Audience, not just publication state, decides what a volunteer may browse:
     * a published staff-only shift must not reach them through the bot.
     */
    public function testStaffOnlyShiftIsNotListedForAVolunteer(): void
    {
        $shift = $this->scenario->shift('Staff Briefing');
        $shift->setAudience(\App\Enum\ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $this->request('GET', '/api/bot/shifts', $this->scenario->user());

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['shifts']);
    }

    public function testStaffOnlyShiftIsNotReadableForAVolunteer(): void
    {
        $shift = $this->scenario->shift('Staff Briefing');
        $shift->setAudience(\App\Enum\ShiftAudience::ALL_STAFF);
        $this->em->flush();

        $this->request('GET', '/api/bot/shifts/'.$shift->getUuid(), $this->scenario->user());

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testApplyAndCancelRoundTrip(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $user = $this->scenario->user(['shift:view', 'shift:apply', 'shift:self'], $this->scenario->type);

        $this->request('POST', '/api/bot/shifts/'.$shift->getUuid().'/apply', $user);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame((string) $user->getUuid(), $this->json()['user_id']);

        $this->request('POST', '/api/bot/shifts/'.$shift->getUuid().'/cancel', $user);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testApplyRefusedForPastShiftReturnsReason(): void
    {
        $shift = $this->scenario->shift('Yesterday', '-1 day', '+2 hours');
        $user = $this->scenario->user(['shift:view', 'shift:apply'], $this->scenario->type);

        $this->request('POST', '/api/bot/shifts/'.$shift->getUuid().'/apply', $user);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('signup_refused', $this->json()['error']);
    }

    public function testApplyWithUnknownVolunteerTypeIsRejected(): void
    {
        $shift = $this->scenario->shift('Morning Gate');
        $user = $this->scenario->user(['shift:view', 'shift:apply'], $this->scenario->type);

        $this->request('POST', '/api/bot/shifts/'.$shift->getUuid().'/apply', $user, self::TOKEN, [
            'volunteer_type_id' => '00000000-0000-4000-8000-000000000000',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('unknown_volunteer_type', $this->json()['error']);
    }

    /** A volunteer cannot sign up as a role the shift does not ask for. */
    public function testApplyWithATypeTheShiftDoesNotNeedIsRefused(): void
    {
        $other = new \App\Entity\VolunteerType('Unwanted Crew');
        $this->em->persist($other);
        $this->em->flush();

        $shift = $this->scenario->shift('Morning Gate');
        $user = $this->scenario->user(['shift:view', 'shift:apply'], $this->scenario->type);

        $this->request('POST', '/api/bot/shifts/'.$shift->getUuid().'/apply', $user, self::TOKEN, [
            'volunteer_type_id' => (string) $other->getUuid(),
        ]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('signup_refused', $this->json()['error']);
    }

    /** The list defaults to shifts that have not ended; finished ones are noise. */
    public function testListExcludesFinishedShiftsByDefault(): void
    {
        $this->scenario->shift('Yesterday', '-2 days', '+2 hours');
        $upcoming = $this->scenario->shift('Tomorrow');

        $this->request('GET', '/api/bot/shifts', $this->scenario->user());

        self::assertResponseIsSuccessful();
        $shifts = $this->json()['shifts'];
        self::assertCount(1, $shifts);
        self::assertSame((string) $upcoming->getUuid(), $shifts[0]['id']);
    }

    /**
     * Filters must run in SQL, before the result cap: filtering the capped page
     * instead would make a filter unable to reach anything the cap excluded.
     */
    public function testListFiltersByDepartmentServerSide(): void
    {
        $mine = $this->scenario->shift('Mine');

        $other = new Department('Other', 'other-'.bin2hex(random_bytes(3)));
        $this->em->persist($other);
        $elsewhere = $this->scenario->shift('Elsewhere');
        $elsewhere->setDepartment($other);
        $this->em->flush();

        $this->request(
            'GET',
            '/api/bot/shifts?department_id='.$this->scenario->department->getUuid(),
            $this->scenario->user(),
        );

        self::assertResponseIsSuccessful();
        $shifts = $this->json()['shifts'];
        self::assertCount(1, $shifts);
        self::assertSame((string) $mine->getUuid(), $shifts[0]['id']);
    }

    public function testListRejectsMalformedFilterUuid(): void
    {
        $this->request('GET', '/api/bot/shifts?department_id=not-a-uuid', $this->scenario->user());

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    // --- manager scoping ------------------------------------------------

    /**
     * The whole reason ActingUserAccess exists: assignment:manage is department
     * scoped, and a manager scoped to one department must not act on another
     * department's shift.
     */
    public function testManagerCannotCheckInOnAnotherDepartmentsShift(): void
    {
        $other = new Department('Other', 'other-'.bin2hex(random_bytes(3)));
        $this->em->persist($other);
        $this->em->flush();

        $manager = $this->scopedManager(['assignment:manage', 'manageshifts:view'], $other);
        $volunteer = $this->scenario->user([], $this->scenario->type);
        $shift = $this->scenario->shift('Morning Gate');
        $this->scenario->signUp($volunteer, $shift);

        $this->request('POST', '/api/bot/manager/shifts/'.$shift->getUuid().'/checkin', $manager, self::TOKEN, [
            'target_user_id' => (string) $volunteer->getUuid(),
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testManagerCanCheckInOnOwnDepartmentsShift(): void
    {
        $manager = $this->scopedManager(['assignment:manage', 'manageshifts:view'], $this->scenario->department);
        $volunteer = $this->scenario->user([], $this->scenario->type);
        $shift = $this->scenario->shift('Morning Gate');
        $this->scenario->signUp($volunteer, $shift);

        $this->request('POST', '/api/bot/manager/shifts/'.$shift->getUuid().'/checkin', $manager, self::TOKEN, [
            'target_user_id' => (string) $volunteer->getUuid(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->json()['checked_in_at']);
    }

    /** A task-less shift still belongs to a department, so scope must still apply. */
    public function testTaskLessShiftIsStillDepartmentScoped(): void
    {
        $other = new Department('Other', 'other-'.bin2hex(random_bytes(3)));
        $this->em->persist($other);
        $this->em->flush();

        $manager = $this->scopedManager(['assignment:manage', 'manageshifts:view'], $other);
        $volunteer = $this->scenario->user([], $this->scenario->type);

        $shift = $this->scenario->shift('Task-less');
        $shift->setShiftTask(null);
        $this->em->flush();
        $this->scenario->signUp($volunteer, $shift);

        $this->request('POST', '/api/bot/manager/shifts/'.$shift->getUuid().'/checkin', $manager, self::TOKEN, [
            'target_user_id' => (string) $volunteer->getUuid(),
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testManagerListOnlyContainsShiftsTheManagerMayAct0n(): void
    {
        $other = new Department('Other', 'other-'.bin2hex(random_bytes(3)));
        $this->em->persist($other);
        $this->em->flush();

        $this->scenario->shift('Morning Gate');
        $manager = $this->scopedManager(['manageshifts:view', 'shift:manage'], $other);

        $this->request('GET', '/api/bot/manager/shifts', $manager);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['shifts']);
    }

    public function testCheckOutRequiresCheckInFirst(): void
    {
        $manager = $this->scopedManager(['assignment:manage', 'manageshifts:view'], $this->scenario->department);
        $volunteer = $this->scenario->user([], $this->scenario->type);
        $shift = $this->scenario->shift('Morning Gate');
        $this->scenario->signUp($volunteer, $shift);

        $this->request('POST', '/api/bot/manager/shifts/'.$shift->getUuid().'/checkout', $manager, self::TOKEN, [
            'target_user_id' => (string) $volunteer->getUuid(),
        ]);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    /** Checking someone in contradicts a no-show and must clear it. */
    public function testCheckInClearsNoShow(): void
    {
        $manager = $this->scopedManager(['assignment:manage', 'manageshifts:view'], $this->scenario->department);
        $volunteer = $this->scenario->user([], $this->scenario->type);
        $shift = $this->scenario->shift('Morning Gate');
        $entry = $this->scenario->signUp($volunteer, $shift);
        $entry->setNoshow(true);
        $this->em->flush();

        $this->request('POST', '/api/bot/manager/shifts/'.$shift->getUuid().'/checkin', $manager, self::TOKEN, [
            'target_user_id' => (string) $volunteer->getUuid(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['noshow']);
    }

    // --- users ----------------------------------------------------------

    public function testMeReturnsTheActingUser(): void
    {
        $user = $this->scenario->user();

        $this->request('GET', '/api/bot/users/me', $user);

        self::assertResponseIsSuccessful();
        self::assertSame((string) $user->getUuid(), $this->json()['id']);
        // The bot only ever acts for a linked volunteer (the resolver enforces it).
        self::assertTrue($this->json()['telegram_linked']);
    }

    /**
     * Telegram consent is per category and defaults to OFF. A linked account is
     * not consent: treating "linked" as permission would message volunteers who
     * never opted in.
     */
    public function testNotificationPreferencesDefaultToNoTelegramConsent(): void
    {
        $user = $this->scenario->user();

        $this->request('GET', '/api/bot/users/me/notification-preferences', $user);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertNotEmpty($body['categories']);
        foreach ($body['categories'] as $category) {
            self::assertFalse($category['telegram'], 'Telegram consent must default to off');
        }
    }

    public function testNotificationPreferencesReflectAnOptIn(): void
    {
        $user = $this->scenario->user();
        static::getContainer()->get(\App\Service\Notification\NotificationService::class)
            ->savePreferences($user, [\App\Notification\NotificationCategories::SHIFT_REMINDER => ['telegram' => true]]);
        $this->em->flush();

        $this->request('GET', '/api/bot/users/me/notification-preferences', $user);

        self::assertResponseIsSuccessful();
        $categories = $this->json()['categories'];
        self::assertTrue($categories[\App\Notification\NotificationCategories::SHIFT_REMINDER]['telegram']);
    }

    public function testOverviewOfAnotherUserRequiresProfileView(): void
    {
        $actor = $this->scenario->user();
        $other = $this->scenario->user();

        $this->request('GET', '/api/bot/users/'.$other->getUuid().'/overview', $actor);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testOwnOverviewIsAllowedWithoutExtraPrivilege(): void
    {
        $user = $this->scenario->user();

        $this->request('GET', '/api/bot/users/'.$user->getUuid().'/overview', $user);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('goodies', $this->json());
        self::assertSame(0, $this->json()['no_show_count']);
    }

    // --- shift groups ---------------------------------------------------

    /** @param Shift[] $shifts */
    private function groupShifts(array $shifts): \App\Entity\ShiftGroup
    {
        $group = new \App\Entity\ShiftGroup($this->scenario->department, 'Main Show');
        $this->em->persist($group);
        foreach ($shifts as $shift) {
            $group->addShift($shift);
        }
        $this->em->flush();

        return $group;
    }

    public function testGroupedShiftCarriesItsSiblings(): void
    {
        $rehearsal = $this->scenario->shift('Rehearsal', 'tomorrow 12:00');
        $show = $this->scenario->shift('Main event', '+2 days 09:00');
        $this->groupShifts([$rehearsal, $show]);

        $this->request('GET', '/api/bot/shifts/'.$show->getUuid(), $this->scenario->user());

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->json()['group']);
        self::assertCount(2, $this->json()['group']['shifts']);
    }

    public function testUngroupedShiftReportsNoGroup(): void
    {
        $shift = $this->scenario->shift('Standalone');

        $this->request('GET', '/api/bot/shifts/'.$shift->getUuid(), $this->scenario->user());

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['group']);
    }

    /**
     * The volunteer has to be shown every shift before the bot commits them to it, so an
     * unconfirmed grouped application is refused rather than silently signing them up for more
     * than they asked about.
     */
    public function testGroupedApplyWithoutConfirmationIsRefused(): void
    {
        $rehearsal = $this->scenario->shift('Rehearsal', 'tomorrow 12:00');
        $show = $this->scenario->shift('Main event', '+2 days 09:00');
        $this->groupShifts([$rehearsal, $show]);
        $user = $this->scenario->user(['shift:view', 'shift:apply', 'shift:self'], $this->scenario->type);

        $this->request('POST', '/api/bot/shifts/'.$show->getUuid().'/apply', $user);

        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('group_confirmation_required', $this->json()['error']);
        self::assertCount(2, $this->json()['group']['shifts']);
        self::assertSame(0, $this->em->getRepository(\App\Entity\ShiftEntry::class)->count(['user' => $user->getId()]));
    }

    public function testConfirmedGroupedApplyCreatesEveryEntry(): void
    {
        $rehearsal = $this->scenario->shift('Rehearsal', 'tomorrow 12:00');
        $show = $this->scenario->shift('Main event', '+2 days 09:00');
        $this->groupShifts([$rehearsal, $show]);
        $user = $this->scenario->user(['shift:view', 'shift:apply', 'shift:self'], $this->scenario->type);

        $this->request('POST', '/api/bot/shifts/'.$show->getUuid().'/apply', $user, self::TOKEN, ['confirm_group' => true]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame((string) $show->getUuid(), $this->json()['shift_id'], 'The requested shift stays at the top level.');
        self::assertCount(2, $this->json()['group_entries']);

        $this->request('POST', '/api/bot/shifts/'.$show->getUuid().'/cancel', $user);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(2, $this->json()['cancelled']);
        self::assertSame(0, $this->em->getRepository(\App\Entity\ShiftEntry::class)->count(['user' => $user->getId()]));
    }

    /**
     * A group holding a shift this volunteer cannot see is not applicable, and the payload must not
     * describe or even acknowledge the hidden member.
     */
    public function testGroupWithAnInvisibleMemberIsHiddenEntirely(): void
    {
        $show = $this->scenario->shift('Main event', '+2 days 09:00');
        $secret = $this->scenario->shift('Secret briefing', 'tomorrow 12:00');
        $secret->setAudience(\App\Enum\ShiftAudience::ALL_STAFF);
        $this->em->flush();
        $this->groupShifts([$secret, $show]);

        $user = $this->scenario->user(['shift:view', 'shift:apply', 'shift:self'], $this->scenario->type);
        $this->request('GET', '/api/bot/shifts/'.$show->getUuid(), $user);

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['group']);
        self::assertStringNotContainsString('Secret briefing', (string) $this->client->getResponse()->getContent());
    }
}
