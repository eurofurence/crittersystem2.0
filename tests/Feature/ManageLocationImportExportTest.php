<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Location;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

final class ManageLocationImportExportTest extends DatabaseWebTestCase
{
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

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seed(string $name, string $alias): void
    {
        $location = new Location($name);
        $location->setAlias($alias);
        $this->em->persist($location);
        $this->em->flush();
    }

    public function testExportRequiresThePrivilege(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['news:view']));
        $this->client->request('GET', '/manage/locations/export');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExportDownloadsJson(): void
    {
        $this->client->loginUser($this->makeUser('rooms', ['location:manage']));
        $this->seed('Main Hall', 'main-hall');

        $this->client->request('GET', '/manage/locations/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('locations.json', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $rows = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('main-hall', $rows[0]['alias']);
    }

    public function testImportFromPastedJson(): void
    {
        $this->client->loginUser($this->makeUser('rooms', ['location:manage']));

        $crawler = $this->client->request('GET', '/manage/locations');
        $form = $crawler->selectButton('Import locations')->form();
        $form['json'] = '[{"name":"Workshop","alias":"workshop","phone":"321"}]';
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/locations');
        $location = $this->em->getRepository(Location::class)->findOneBy(['alias' => 'workshop']);
        self::assertNotNull($location);
        self::assertSame('321', $location->getPhone());
    }

    public function testImportFromUploadedFile(): void
    {
        $this->client->loginUser($this->makeUser('rooms', ['location:manage']));

        $path = tempnam(sys_get_temp_dir(), 'loc').'.json';
        file_put_contents($path, '[{"name":"Storage","alias":"storage"}]');

        $crawler = $this->client->request('GET', '/manage/locations');
        $form = $crawler->selectButton('Import locations')->form();
        $form['file']->upload($path);
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/locations');
        self::assertNotNull($this->em->getRepository(Location::class)->findOneBy(['alias' => 'storage']));
        @unlink($path);
    }

    public function testInvalidJsonIsRejectedGracefully(): void
    {
        $this->client->loginUser($this->makeUser('rooms', ['location:manage']));

        $crawler = $this->client->request('GET', '/manage/locations');
        $form = $crawler->selectButton('Import locations')->form();
        $form['json'] = 'not json at all';
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/locations');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Invalid JSON');
        self::assertCount(0, $this->em->getRepository(Location::class)->findAll());
    }
}
