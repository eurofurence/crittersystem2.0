<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Location;
use App\Entity\Privilege;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageLocationTest extends WebTestCase
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
    }

    /** @param string[] $privileges */
    private function makeUser(string $name, array $privileges): User
    {
        $group = new Group('Group '.$name, 'group-'.$name);
        foreach ($privileges as $privilegeName) {
            $privilege = new Privilege($privilegeName);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)
            ->setEmail($name.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/manage/locations');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testUserWithoutPrivilegeIsForbidden(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['news:view']));
        $this->client->request('GET', '/manage/locations');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPrivilegedUserCanCreateLocation(): void
    {
        $this->client->loginUser($this->makeUser('rooms', ['location:manage']));

        $crawler = $this->client->request('GET', '/manage/locations/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form([
            'location[name]' => 'Backstage',
            'location[dect]' => '999',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/manage/locations');

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Backstage');

        $location = $this->em->getRepository(Location::class)->findOneBy(['name' => 'Backstage']);
        self::assertNotNull($location);
        self::assertSame('999', $location->getDect());
    }
}
