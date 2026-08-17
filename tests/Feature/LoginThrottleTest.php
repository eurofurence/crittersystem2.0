<?php

namespace App\Tests\Feature;

use App\Entity\LoginLockout;
use App\Entity\User;
use App\Repository\LoginLockoutRepository;
use App\Security\LoginThrottle;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Brute-force protection on the login form.
 *
 * The invariant these protect: once a subject is timed out, the *correct* password is refused too,
 * and the page says nothing beyond "invalid credentials". A lockout that let the right password
 * through would only delay a brute force, and one that announced itself would confirm the account
 * exists and tell the attacker when to come back.
 */
final class LoginThrottleTest extends DatabaseWebTestCase
{
    private const PASSWORD = 'secret123';

    private function user(string $name = 'target'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function attemptLogin(string $username, string $password, string $ip = '203.0.113.1'): void
    {
        $crawler = $this->client->request('GET', '/login', [], [], ['REMOTE_ADDR' => $ip]);
        $this->client->submit(
            $crawler->filter('form[action$="/login"]')->form([
                '_username' => $username,
                '_password' => $password,
            ]),
            [],
            ['REMOTE_ADDR' => $ip],
        );
    }

    private function lockouts(): LoginLockoutRepository
    {
        return static::getContainer()->get(LoginLockoutRepository::class);
    }

    private function lockout(string $scope): ?LoginLockout
    {
        $this->em->clear();

        return $this->lockouts()->findOneBy(['scope' => $scope]);
    }

    public function testRepeatedFailuresFromOneAddressLockThatAddressButNotTheAccount(): void
    {
        $this->user();

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; ++$i) {
            $this->attemptLogin('target', 'wrong-password');
        }

        self::assertNotNull($this->lockout(LoginLockout::SCOPE_IP));
        self::assertNull(
            $this->lockout(LoginLockout::SCOPE_ACCOUNT),
            'one source is a forgetful volunteer or an attacker the address lock already stops; '
            .'locking the account here would let anyone shut a volunteer out at will',
        );
    }

    public function testTheCorrectPasswordIsStillRefusedWhileTheAddressIsLockedOut(): void
    {
        $this->user();

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; ++$i) {
            $this->attemptLogin('target', 'wrong-password');
        }

        $this->attemptLogin('target', self::PASSWORD);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Invalid credentials');
        self::assertSelectorNotExists('a[href="/logout"]');
    }

    public function testFailuresFromSeveralAddressesLockTheAccountItself(): void
    {
        $this->user();

        $this->attemptLogin('target', 'wrong-password', '203.0.113.1');
        $this->attemptLogin('target', 'wrong-password', '203.0.113.2');
        $this->attemptLogin('target', 'wrong-password', '203.0.113.3');

        $account = $this->lockout(LoginLockout::SCOPE_ACCOUNT);
        self::assertNotNull($account, 'failures arriving from several sources are the brute-force signature');
        self::assertSame('target', $account->getSubject());
        self::assertGreaterThanOrEqual(LoginThrottle::MIN_SOURCES_FOR_ACCOUNT_LOCK, $account->getSourceCount());
    }

    /**
     * An account lockout follows the account, not the source. The correct password is then offered
     * from an address the throttle has never seen, so only the account-scoped lockout can be
     * refusing it.
     */
    public function testAnAccountLockoutHoldsEvenFromAnUnseenAddress(): void
    {
        $this->user();

        $this->attemptLogin('target', 'wrong-password', '203.0.113.1');
        $this->attemptLogin('target', 'wrong-password', '203.0.113.2');
        $this->attemptLogin('target', 'wrong-password', '203.0.113.3');

        $this->attemptLogin('target', self::PASSWORD, '198.51.100.7');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Invalid credentials');
    }

    public function testCasingDoesNotBuyAFreshAllowance(): void
    {
        $this->user();

        $this->attemptLogin('target', 'wrong-password', '203.0.113.1');
        $this->attemptLogin('TARGET', 'wrong-password', '203.0.113.2');
        $this->attemptLogin('Target', 'wrong-password', '203.0.113.3');

        self::assertNotNull($this->lockout(LoginLockout::SCOPE_ACCOUNT));
    }

    public function testAnExpiredLockoutStopsBlocking(): void
    {
        $this->user();

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; ++$i) {
            $this->attemptLogin('target', 'wrong-password');
        }

        $lockout = $this->lockout(LoginLockout::SCOPE_IP);
        self::assertNotNull($lockout);
        $this->em->getConnection()->executeStatement(
            'UPDATE login_lockouts SET locked_until = :past WHERE id = :id',
            ['past' => (new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP'), 'id' => $lockout->getId()],
        );
        $this->em->clear();

        $this->attemptLogin('target', self::PASSWORD);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Releasing a lockout also clears the failures behind it. Leaving them would put the address
     * straight back over the threshold, so the lift would last exactly one wrong password.
     */
    public function testLiftingALockoutSurvivesTheNextWrongPassword(): void
    {
        $this->user();

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; ++$i) {
            $this->attemptLogin('target', 'wrong-password');
        }

        $lockout = $this->lockout(LoginLockout::SCOPE_IP);
        self::assertNotNull($lockout);
        static::getContainer()->get(LoginThrottle::class)->release($lockout);

        $this->attemptLogin('target', 'wrong-password');
        self::assertNull($this->lockout(LoginLockout::SCOPE_IP));

        $this->attemptLogin('target', self::PASSWORD);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * A successful sign-in resets the failure counter. The sequence is one short of the threshold,
     * then a success, then another failure: without the reset that third failure overall would tip
     * the counter and lock the address.
     */
    public function testASuccessfulSignInClearsTheAccountsFailureHistory(): void
    {
        $this->user();

        $this->attemptLogin('target', 'wrong-password');
        $this->attemptLogin('target', 'wrong-password');
        $this->attemptLogin('target', self::PASSWORD);
        $this->client->request('GET', '/logout');

        $this->attemptLogin('target', 'wrong-password');

        self::assertNull($this->lockout(LoginLockout::SCOPE_IP));
    }

    public function testAStaleCsrfTokenDoesNotCountAsAGuess(): void
    {
        $this->user();

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES + 1; ++$i) {
            $this->client->request('POST', '/login', [
                '_username' => 'target',
                '_password' => self::PASSWORD,
                '_csrf_token' => 'stale',
            ]);
        }

        self::assertNull(
            $this->lockout(LoginLockout::SCOPE_IP),
            'a login page left open in a tab must not lock its owner out on the third try',
        );

        $this->attemptLogin('target', self::PASSWORD);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * The submitted identifier is arbitrary caller input, and the columns the throttle writes it to
     * are not: an overlong username must be throttled rather than crash the insert.
     */
    public function testAnAbsurdlyLongUsernameIsThrottledRatherThanCrashingTheInsert(): void
    {
        $overlong = str_repeat('z', 4000);

        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; ++$i) {
            $this->attemptLogin($overlong, 'wrong-password');
        }

        self::assertResponseRedirects('/login');
        self::assertNotNull($this->lockout(LoginLockout::SCOPE_IP));
    }

    public function testAnAttemptWithNoMatchingAccountStillCountsTowardsTheAddressLock(): void
    {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; ++$i) {
            $this->attemptLogin('nobody-'.$i, 'wrong-password');
        }

        self::assertNotNull(
            $this->lockout(LoginLockout::SCOPE_IP),
            'spraying invented usernames must be throttled, or the attacker gets unlimited guesses '
            .'simply by never repeating a name',
        );
    }
}
