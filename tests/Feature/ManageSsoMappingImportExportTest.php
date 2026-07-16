<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\SsoGroupMapping;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

final class ManageSsoMappingImportExportTest extends DatabaseWebTestCase
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

    /**
     * Log in and clear the two-factor step-up gate. The SSO-mapping area is step-up guarded
     * (it exposes raw identity-provider group ids), so a privileged user must have 2FA enrolled
     * and a fresh confirmation before the controller runs.
     */
    private function loginWithStepUp(User $user): void
    {
        $user->setTotpSecret('SECRET')->setTwoFactorEnabled(true);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');
        $session = $this->client->getRequest()->getSession();
        $session->set('_mfa_verified_at', time());
        $session->save();
    }

    public function testExportRequiresThePrivilege(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['news:view']));
        $this->client->request('GET', '/manage/sso-mappings/export');
        self::assertResponseStatusCodeSame(403);
    }

    public function testExportDownloadsJson(): void
    {
        $this->loginWithStepUp($this->makeUser('sso', ['rbac:ssomap:manage']));
        $this->em->persist((new SsoGroupMapping('GRP-1'))->setName('Art Show'));
        $this->em->flush();

        $this->client->request('GET', '/manage/sso-mappings/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('sso-mappings.json', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $rows = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('GRP-1', $rows[0]['id']);
    }

    public function testImportFromPastedJson(): void
    {
        $this->loginWithStepUp($this->makeUser('sso', ['rbac:ssomap:manage']));

        $crawler = $this->client->request('GET', '/manage/sso-mappings');
        $form = $crawler->selectButton('Import mappings')->form();
        $form['json'] = '[{"id":"GRP-9","name":"Logistics"}]';
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/sso-mappings');
        self::assertNotNull($this->em->getRepository(SsoGroupMapping::class)->findOneBy(['ssoGroupId' => 'GRP-9']));
    }

    public function testImportFromUploadedFile(): void
    {
        $this->loginWithStepUp($this->makeUser('sso', ['rbac:ssomap:manage']));

        $path = tempnam(sys_get_temp_dir(), 'sso').'.json';
        file_put_contents($path, '[{"id":"GRP-U","name":"Uploaded"}]');

        $crawler = $this->client->request('GET', '/manage/sso-mappings');
        $form = $crawler->selectButton('Import mappings')->form();
        $form['file']->upload($path);
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/sso-mappings');
        self::assertNotNull($this->em->getRepository(SsoGroupMapping::class)->findOneBy(['ssoGroupId' => 'GRP-U']));
        @unlink($path);
    }

    public function testInvalidJsonIsRejectedGracefully(): void
    {
        $this->loginWithStepUp($this->makeUser('sso', ['rbac:ssomap:manage']));

        $crawler = $this->client->request('GET', '/manage/sso-mappings');
        $form = $crawler->selectButton('Import mappings')->form();
        $form['json'] = 'not json at all';
        $this->client->submit($form);

        self::assertResponseRedirects('/manage/sso-mappings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Invalid JSON');
        self::assertCount(0, $this->em->getRepository(SsoGroupMapping::class)->findAll());
    }
}
