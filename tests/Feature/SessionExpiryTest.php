<?php

namespace App\Tests\Feature;

use App\Entity\User;
use App\Security\LoginFormAuthenticator;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What an expired session does to a page that polls in the background.
 *
 * The failure this protects against: the entry point answered a poll with a redirect to /login, `fetch`
 * followed it, and the widget injected the whole login document into the navbar - while the previous
 * user's personal data stayed on screen. The poll also overwrote the "return to" path, so even a clean
 * re-login landed on a bare /status fragment.
 */
final class SessionExpiryTest extends DatabaseWebTestCase
{
    private const TARGET_PATH_KEY = '_security.main.target_path';

    private function user(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('idler')->setEmail('idler@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testAPollFromAnExpiredSessionGets401NotTheLoginPage(): void
    {
        $this->client->request('GET', '/status', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHasHeader(LoginFormAuthenticator::SESSION_EXPIRED_HEADER);
        self::assertSame('', $this->client->getResponse()->getContent(), 'a body here is a page the widget would inject');
    }

    public function testTheNotificationBellBehavesTheSameWay(): void
    {
        $this->client->request('GET', '/notifications/bell', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testATurboFrameRequestIsAlsoRefusedWithout401Page(): void
    {
        $this->client->request('GET', '/status', [], [], ['HTTP_TURBO_FRAME' => 'operational-status']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAPollDoesNotBecomeThePlaceTheUserIsSentBackTo(): void
    {
        $this->client->request('GET', '/status', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertFalse(
            $this->client->getRequest()->getSession()->has(self::TARGET_PATH_KEY),
            'a background poll must never overwrite where the user was',
        );
    }

    public function testANormalNavigationStillRedirectsToLoginAndIsRemembered(): void
    {
        $this->client->request('GET', '/profile');

        self::assertResponseRedirects('/login');
        self::assertStringEndsWith(
            '/profile',
            (string) $this->client->getRequest()->getSession()->get(self::TARGET_PATH_KEY),
        );
    }

    public function testTheReturnPathSendsTheUserBackToThePageTheyWereOn(): void
    {
        $user = $this->user();

        $crawler = $this->client->request('GET', '/login?return=/my-shifts');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'idler',
            '_password' => 'secret123',
        ]));

        self::assertResponseRedirects('/my-shifts');
        self::assertNotNull($user->getId());
    }

    /**
     * The return path is attacker-supplied, so it must never be able to point off-site - otherwise the
     * login page becomes an open redirect that phishing can hang a credible URL on.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostileReturnPaths')]
    public function testAnOffSiteReturnPathIsIgnored(string $return): void
    {
        $this->user();

        $crawler = $this->client->request('GET', '/login?return='.urlencode($return));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'idler',
            '_password' => 'secret123',
        ]));

        self::assertResponseRedirects('/dashboard', 302, sprintf('"%s" must not survive as a redirect target', $return));
    }

    public static function hostileReturnPaths(): iterable
    {
        yield 'absolute url' => ['https://evil.example/steal'];
        yield 'protocol relative' => ['//evil.example/steal'];
        yield 'backslash' => ['\\\\evil.example/steal'];
        yield 'scheme relative no slash' => ['evil.example'];
    }

    public function testASessionIdleBeyondTheConfiguredLimitIsTreatedAsSignedOut(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_SESSION_IDLE_MINUTES, 1);
        $store->flush();

        $this->client->loginUser($this->user());
        $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        // Age the activity stamp past the one-minute limit.
        $session = $this->client->getRequest()->getSession();
        $session->set('_last_activity', time() - 120);
        $session->save();

        $this->client->request('GET', '/status', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(401, 'the idle limit is enforced from the admin-editable config');
    }

    public function testAnActiveSessionIsKeptAliveByItsOwnPolling(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_SESSION_IDLE_MINUTES, 30);
        $store->flush();

        $this->client->loginUser($this->user());
        $this->client->request('GET', '/dashboard');

        // A poll 29 minutes in: still inside the window, and it refreshes the stamp. This is what keeps
        // the bounty board signed in on a display nobody touches.
        $session = $this->client->getRequest()->getSession();
        $session->set('_last_activity', time() - (29 * 60));
        $session->save();

        $this->client->request('GET', '/status', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $refreshed = $this->client->getRequest()->getSession()->get('_last_activity');
        self::assertGreaterThan(time() - 60, $refreshed, 'the poll refreshed the session rather than letting it lapse');
    }
}
