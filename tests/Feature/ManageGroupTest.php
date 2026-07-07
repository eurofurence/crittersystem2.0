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

final class ManageGroupTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);

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
    private function makeUser(string $name, array $privileges, ?string $role = null): User
    {
        $group = new Group('Group '.$name, 'group-'.$name, $role);
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

    private function seededPrivilege(string $name): Privilege
    {
        $privilege = new Privilege($name);
        $this->em->persist($privilege);
        $this->em->flush();

        return $privilege;
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/manage/groups');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testUserWithoutViewPrivilegeIsForbidden(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['news:view']));
        $this->client->request('GET', '/manage/groups');
        self::assertResponseStatusCodeSame(403);
    }

    public function testViewerCanListButCannotCreate(): void
    {
        $this->client->loginUser($this->makeUser('viewer', ['rbac:group:view']));

        $this->client->request('GET', '/manage/groups');
        self::assertResponseIsSuccessful();

        // Mutations require rbac:group:manage.
        $this->client->request('GET', '/manage/groups/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCanCreateGroupWithPermissions(): void
    {
        $this->client->loginUser($this->makeUser('boss', ['rbac:group:view', 'rbac:group:manage']));
        $this->seededPrivilege('news:manage');

        $crawler = $this->client->request('GET', '/manage/groups/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $values = $form->getPhpValues();
        $values['group']['name'] = 'Press Team';
        $values['group']['role'] = 'ROLE_STAFF';
        $values['group']['privileges'] = ['news:manage'];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/manage/groups');

        $group = $this->em->getRepository(Group::class)->findOneBy(['name' => 'Press Team']);
        self::assertNotNull($group);
        self::assertSame('ROLE_STAFF', $group->getRole());
        self::assertSame('press-team', $group->getSlug());
        $names = array_map(static fn (Privilege $p) => $p->getName(), $group->getPrivileges()->toArray());
        self::assertContains('news:manage', $names);
    }
}
