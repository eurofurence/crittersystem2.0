<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

final class ManageDepartmentImportExportTest extends DatabaseWebTestCase
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

    public function testExportRequiresThePrivilege(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['news:view']));
        $this->client->request('GET', '/manage/departments/export');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExportDownloadsJson(): void
    {
        $this->client->loginUser($this->makeUser('boss', ['shift:manage']));
        $this->em->persist(new Department('Logistics', 'logistics'));
        $this->em->flush();

        $this->client->request('GET', '/manage/departments/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('departments.json', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $rows = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('logistics', $rows[0]['slug']);
    }

    public function testImportFromPastedJson(): void
    {
        $this->client->loginUser($this->makeUser('boss', ['shift:manage']));

        $crawler = $this->client->request('GET', '/manage/departments');
        $form = $crawler->selectButton('Import departments')->form();
        $form['json'] = '[{"name":"Workshop","slug":"workshop"}]';
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/departments');
        self::assertNotNull($this->em->getRepository(Department::class)->findOneBy(['slug' => 'workshop']));
    }

    public function testImportFromUploadedFile(): void
    {
        $this->client->loginUser($this->makeUser('boss', ['shift:manage']));

        $path = tempnam(sys_get_temp_dir(), 'dept').'.json';
        file_put_contents($path, '[{"name":"Storage","slug":"storage"}]');

        $crawler = $this->client->request('GET', '/manage/departments');
        $form = $crawler->selectButton('Import departments')->form();
        $form['file']->upload($path);
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/departments');
        self::assertNotNull($this->em->getRepository(Department::class)->findOneBy(['slug' => 'storage']));
        @unlink($path);
    }

    public function testInvalidJsonIsRejectedGracefully(): void
    {
        $this->client->loginUser($this->makeUser('boss', ['shift:manage']));

        $crawler = $this->client->request('GET', '/manage/departments');
        $form = $crawler->selectButton('Import departments')->form();
        $form['json'] = 'not json at all';
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/departments');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Invalid JSON');
        self::assertCount(0, $this->em->getRepository(Department::class)->findAll());
    }
}
