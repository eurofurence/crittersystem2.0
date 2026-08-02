<?php

namespace App\Tests\Browser;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Mercure\Topics;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The live regions in a real browser.
 *
 * The PHPUnit suite renders this markup and never executes the controllers that drive it, which is
 * exactly how a Stimulus lifecycle crash ships behind a 200 and correct HTML. Here the controllers
 * actually connect, and an uncaught exception in one shows up as a console error and fails the test.
 *
 * No hub runs in this environment, so nothing is pushed. That is deliberate and is itself the case
 * worth protecting: with the hub unreachable the page must still render, still work, and still
 * report nothing to the console. A hub outage during an event degrades the live regions; it must not
 * break the application.
 */
final class LiveUpdatesBrowserTest extends BrowserTestCase
{
    private function volunteer(): User
    {
        $group = new Group('Volunteer', 'volunteer-'.bin2hex(random_bytes(2)), null);
        foreach (['news:view', 'shift:view', 'shift:self'] as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('liveuser')->setEmail('liveuser@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testLiveRegionsConnectAndAreScopedToTheSignedInUser(): void
    {
        $user = $this->volunteer();

        $this->browse();
        $this->signIn($user, 'secret123');

        $html = $this->client->getPageSource();

        // No hub is configured in this environment, so the page must not advertise one - see the
        // class docblock. The heartbeat runs regardless; it is what keeps the session alive.
        self::assertStringNotContainsString('name="mercure-hub"', $html);
        self::assertStringContainsString('name="heartbeat-url"', $html);

        // Both navbar regions are wired to this user's own topics.
        self::assertStringContainsString(Topics::userNotifications($user), $html);
        self::assertStringContainsString(Topics::userStatus($user), $html);
        self::assertStringContainsString('data-controller="live-stream"', $html);

        // The polling controller these replaced no longer exists anywhere in the application.
        self::assertStringNotContainsString('live-refresh', $html);

        $this->assertNoConsoleErrors('the dashboard with live regions');
    }

    /**
     * The token is never readable by page script.
     *
     * HttpOnly and the hub-path scope are asserted at the HTTP level in
     * {@see \App\Tests\Feature\MercureCookieDeliveryTest}; what only a browser can show is that the
     * document really cannot read it back, which is what stops an injected script from replaying it
     * against the hub.
     */
    public function testSubscriberTokenIsNotReadableByPageScript(): void
    {
        $user = $this->volunteer();

        $this->browse();
        $this->signIn($user, 'secret123');

        self::assertStringNotContainsString(
            'mercureAuthorization',
            (string) $this->client->executeScript('return document.cookie;'),
        );
    }

    /** A user still in onboarding gets no token, and the wizard renders clean. */
    public function testOnboardingWizardHasNoLiveRegionsAndNoInjectedDocument(): void
    {
        $group = new Group('Volunteer', 'volunteer-'.bin2hex(random_bytes(2)), null);
        $privilege = new Privilege('news:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('newcomer')->setEmail('newcomer@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        // Deliberately not onboarded.
        $this->em->persist($user);
        $this->em->flush();

        $this->browse();
        $this->client->request('GET', '/login');
        $this->client->waitFor('form input[name="_username"]', 10);
        $this->client->submitForm('Sign in', ['_username' => 'newcomer', '_password' => 'secret123']);
        $this->client->waitFor('form', 10);

        $html = $this->client->getPageSource();

        self::assertStringNotContainsString('name="mercure-hub"', $html);

        // No live regions either: the gate refuses the fragments they fetch, so rendering them would
        // only produce a timer that is turned away every time.
        self::assertStringNotContainsString('data-controller="live-stream"', $html);

        // The defect this replaced: a background request was answered with the whole onboarding
        // document and injected into the navbar, so the page contained a second <html>.
        self::assertSame(1, substr_count(strtolower($html), '<html'), 'a document was injected into the page');

        $this->assertNoConsoleErrors('the onboarding wizard');
    }
}
