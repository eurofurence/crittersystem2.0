<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\TogglesSso;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Switching password sign-in off once an identity provider carries the flow.
 *
 * The switch has to withhold authentication, not just the form. Hiding the fields while the
 * authenticator still accepted a posted password would leave the whole credential surface open to
 * anything that skips the page - which is every tool an attacker would actually use.
 */
final class PasswordLoginDisabledTest extends DatabaseWebTestCase
{
    use TogglesSso;

    private const PASSWORD = 'secret123';

    protected function tearDown(): void
    {
        $this->restoreSsoEnv();
        parent::tearDown();
    }

    private function user(string $name, ?string $role = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $user->completeOnboarding();

        if ($role !== null) {
            $group = new Group(ucfirst($name).' group', $name.'-group', $role);
            $this->em->persist($group);
            $user->addGroup($group);
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function disablePasswordLogin(): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_PASSWORD_LOGIN_ENABLED, false);
        $store->flush();
    }

    /** Posts credentials straight at the firewall, the way anything but a browser would. */
    private function postCredentials(string $username): void
    {
        $this->client->request('GET', '/login');
        $this->client->request('POST', '/login', [
            '_username' => $username,
            '_password' => self::PASSWORD,
            '_csrf_token' => static::getContainer()->get('security.csrf.token_manager')->getToken('authenticate')->getValue(),
        ]);
    }

    public function testACorrectPasswordIsRefusedOnceTheSwitchIsOff(): void
    {
        $this->bootWithSso();
        $this->user('volunteer');
        $this->disablePasswordLogin();

        $this->postCredentials('volunteer');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains(
            'body',
            'Invalid credentials',
            'naming the real reason would confirm to an anonymous caller that this account exists',
        );
    }

    public function testAdministratorsKeepPasswordAccessSoAFailedProviderCannotLockThemOut(): void
    {
        $this->bootWithSso();
        $this->user('boss', 'ROLE_ADMIN');
        $this->disablePasswordLogin();

        $this->postCredentials('boss');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheSwitchIsEditedOnTheStepUpGuardedSsoPage(): void
    {
        $this->bootWithSso();

        $group = new Group('Root', 'root-'.bin2hex(random_bytes(2)), 'ROLE_ADMIN');
        $privilege = new Privilege('global:admin');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $admin = new User();
        $admin->setName('root')->setEmail('root@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $admin->addGroup($group)->setTotpSecret('SECRET')->setTwoFactorEnabled(true);
        $admin->completeOnboarding();
        $this->em->persist($admin);
        $this->em->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/dashboard');
        $session = $this->client->getRequest()->getSession();
        $session->set('_mfa_verified_at', time());
        $session->save();

        $crawler = $this->client->request('GET', '/admin/sso');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form:has([name="local_login[passwordLoginEnabled]"])')->form([
            'local_login[passwordLoginEnabled]' => false,
        ]));

        self::assertResponseRedirects('/admin/sso');
        self::assertFalse(static::getContainer()->get(EventConfigStore::class)
            ->getBool(EventConfigStore::KEY_PASSWORD_LOGIN_ENABLED, true));
    }

    public function testTheSwitchIsIgnoredWhileSsoIsOffSoNobodyIsLockedOutOfAnInstallWithNoProvider(): void
    {
        $this->user('volunteer');
        $this->disablePasswordLogin();

        $this->postCredentials('volunteer');

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
