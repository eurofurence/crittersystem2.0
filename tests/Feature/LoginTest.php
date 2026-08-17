<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends DatabaseWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();

        $group = new Group('Volunteer', 'volunteer');
        $privilege = new Privilege('news:view');
        $this->em->persist($privilege);
        $group->addPrivilege($privilege);
        $this->em->persist($group);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('tester')
            ->setEmail('tester@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();
    }

    private function submitLogin(string $username, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $username,
            '_password' => $password,
        ]);
        $this->client->submit($form);
    }

    public function testLoginPageRenders(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="_username"]');
    }

    /**
     * A username sign-in lands on /news. The group badges are asserted on /dashboard, because that
     * is where they live now that the dashboard is no longer the landing page.
     */
    public function testValidLoginWithUsernameRedirectsToNews(): void
    {
        $this->submitLogin('tester', 'secret123');
        self::assertResponseRedirects('/news');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'tester');

        $this->client->request('GET', '/dashboard');
        self::assertSelectorTextContains('body', 'Volunteer');
    }

    public function testValidLoginWithEmail(): void
    {
        $this->submitLogin('tester@example.com', 'secret123');

        self::assertResponseRedirects('/news');
    }

    public function testInvalidPasswordStaysOnLoginWithError(): void
    {
        $this->submitLogin('tester', 'wrong-password');
        self::assertResponseRedirects('/login');

        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
    }

    public function testLastLoginIsRecorded(): void
    {
        $this->submitLogin('tester', 'secret123');

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['name' => 'tester']);
        self::assertNotNull($user);
        self::assertNotNull($user->getLastLoginAt());
    }

    public function testLogoutClearsAuthentication(): void
    {
        $this->submitLogin('tester', 'secret123');
        $this->client->request('GET', '/logout');
        self::assertResponseRedirects();

        $this->client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }
}
