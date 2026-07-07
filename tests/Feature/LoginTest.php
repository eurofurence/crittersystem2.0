<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available: '.$e->getMessage());
        }

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

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

    public function testValidLoginWithUsernameRedirectsToDashboard(): void
    {
        $this->submitLogin('tester', 'secret123');
        self::assertResponseRedirects('/dashboard');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'tester');
        self::assertSelectorTextContains('body', 'Volunteer');
    }

    public function testValidLoginWithEmail(): void
    {
        $this->submitLogin('tester@example.com', 'secret123');

        self::assertResponseRedirects('/dashboard');
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
