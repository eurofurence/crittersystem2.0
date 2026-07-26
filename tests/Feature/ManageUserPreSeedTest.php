<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\SsoGroupMapping;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * Protects the access model of the user pre-seeder: only holders of the admin-level, two-factor
 * flagged `user:preseed` privilege reach it, a fresh step-up is required, and the upload is a
 * no-write preview until the admin confirms.
 */
final class ManageUserPreSeedTest extends DatabaseWebTestCase
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

    private function seedMapping(): void
    {
        $this->em->persist(new Group('Department staff', 'department-staff'));
        $department = new Department('Art Show', 'art-show');
        $this->em->persist($department);
        $this->em->persist((new SsoGroupMapping('GRP-1'))->setName('Art Show')->setDepartment($department));
        $this->em->flush();
    }

    private function dumpFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'preseed').'.json';
        file_put_contents($path, json_encode([
            ['id' => 'GRP-1', 'type' => 'department', 'name' => 'Art Show', 'users' => [
                ['user_id' => 'SUB-1', 'username' => 'importedone', 'level' => 'member'],
            ]],
        ]));

        return $path;
    }

    public function testRequiresThePrivilege(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['news:view']));
        $this->client->request('GET', '/manage/user-preseed');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRequiresAFreshTwoFactorStepUp(): void
    {
        $user = $this->makeUser('seeder', ['user:preseed']);
        $user->setTotpSecret('SECRET')->setTwoFactorEnabled(true);
        $this->em->flush();

        // Logged in and privileged, but no fresh step-up in the session.
        $this->client->loginUser($user);
        $this->client->request('GET', '/manage/user-preseed');

        self::assertResponseRedirects();
        self::assertStringContainsString('/2fa', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testPreviewDoesNotWriteUntilConfirmed(): void
    {
        $this->seedMapping();
        $this->loginWithStepUp($this->makeUser('seeder', ['user:preseed']));

        $crawler = $this->client->request('GET', '/manage/user-preseed');
        $form = $crawler->selectButton('Preview import')->form();
        $form['file']->upload($this->dumpFile());
        $this->client->submit($form);

        // The upload must redirect to a GET preview (Post/Redirect/Get); a rendered
        // response to the POST is silently dropped by Turbo in the browser.
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression(
            '#/manage/user-preseed/preview/[0-9a-f]{32}$#',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertNull(
            $this->em->getRepository(User::class)->findOneBy(['ssoUserId' => 'SUB-1']),
            'the preview must not create any user',
        );
    }

    public function testConfirmCreatesUsers(): void
    {
        $this->seedMapping();
        $this->loginWithStepUp($this->makeUser('seeder', ['user:preseed']));

        $crawler = $this->client->request('GET', '/manage/user-preseed');
        $form = $crawler->selectButton('Preview import')->form();
        $form['file']->upload($this->dumpFile());
        $this->client->submit($form);
        $previewCrawler = $this->client->followRedirect();

        $applyForm = $previewCrawler->selectButton('Create users')->form();
        $this->client->submit($applyForm);

        self::assertResponseRedirects('/manage/user-preseed');
        $created = $this->em->getRepository(User::class)->findOneBy(['ssoUserId' => 'SUB-1']);
        self::assertNotNull($created);
        self::assertSame(User::SOURCE_SSO, $created->getAccountSource());
    }
}
