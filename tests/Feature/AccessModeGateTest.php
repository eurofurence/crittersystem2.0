<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Protects the event-wide Access mode gate: who may reach the system in each mode, that a
 * non-qualifying user is signed out (not merely shown a page), that the digital badge and the
 * login flow stay reachable while restricted, and that the machine API is gated too.
 */
final class AccessModeGateTest extends DatabaseWebTestCase
{
    private function makeUser(string $name, ?string $role): User
    {
        $group = new Group('Group '.$name, 'group-'.$name, $role);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)
            ->setEmail($name.'@example.com')
            ->setApiKey('key-'.$name);
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function setMode(string $mode): void
    {
        $store = static::getContainer()->get(EventConfigStore::class);
        $store->set(EventConfigStore::KEY_ACCESS_MODE, $mode);
        $store->flush();
    }

    private function redirectedToUnavailable(): bool
    {
        $location = (string) $this->client->getResponse()->headers->get('Location');

        return str_contains($location, '/unavailable');
    }

    public function testPublicModeAllowsVolunteer(): void
    {
        $this->setMode('public');
        $this->client->loginUser($this->makeUser('vol', null));

        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
    }

    public function testStaffModeBlocksVolunteer(): void
    {
        $this->setMode('staff');
        $this->client->loginUser($this->makeUser('vol', null));

        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects();
        self::assertTrue($this->redirectedToUnavailable());
    }

    public function testStaffModeAllowsStaff(): void
    {
        $this->setMode('staff');
        $this->client->loginUser($this->makeUser('staffer', 'ROLE_STAFF'));

        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
    }

    public function testAdminModeBlocksStaff(): void
    {
        $this->setMode('admin');
        $this->client->loginUser($this->makeUser('staffer', 'ROLE_STAFF'));

        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects();
        self::assertTrue($this->redirectedToUnavailable());
    }

    /** "Admin only" also admits sub-admins, per the configured semantics. */
    public function testAdminModeAllowsSubAdmin(): void
    {
        $this->setMode('admin');
        $this->client->loginUser($this->makeUser('sub', 'ROLE_SUBADMIN'));

        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
    }

    /** An admin can never lock themselves out by tightening the gate. */
    public function testAdminModeAllowsAdmin(): void
    {
        $this->setMode('admin');
        $this->client->loginUser($this->makeUser('boss', 'ROLE_ADMIN'));

        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
    }

    /** The digital badge must stay reachable so a gated volunteer can identify themselves to security. */
    public function testDigitalIdReachableWhenGated(): void
    {
        $this->setMode('staff');
        $this->client->loginUser($this->makeUser('vol', null));

        $this->client->request('GET', '/digital-id');

        self::assertResponseIsSuccessful();
    }

    public function testGatedVolunteerFormLoginLandsOnNotice(): void
    {
        $this->makeUser('vol', null);
        $this->setMode('staff');

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'vol',
            '_password' => 'secret123',
        ]));

        self::assertResponseRedirects();
        self::assertTrue($this->redirectedToUnavailable());
    }

    public function testUnavailablePageRedirectsInPublicMode(): void
    {
        $this->setMode('public');
        $this->client->loginUser($this->makeUser('vol', null));

        $this->client->request('GET', '/unavailable');

        self::assertResponseRedirects('/news');
    }

    public function testApiKeyBlockedWhenGated(): void
    {
        $this->setMode('staff');
        $volunteer = $this->makeUser('vol', null);

        $this->client->request('GET', '/api/v0-beta/shifts', [], [], ['HTTP_X-API-Key' => $volunteer->getApiKey()]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testApiInfoReadableWhenGated(): void
    {
        $this->setMode('staff');
        $volunteer = $this->makeUser('vol', null);

        $this->client->request('GET', '/api/v0-beta/info', [], [], ['HTTP_X-API-Key' => $volunteer->getApiKey()]);

        self::assertResponseIsSuccessful();
    }
}
