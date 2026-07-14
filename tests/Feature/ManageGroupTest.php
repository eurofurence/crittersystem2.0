<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ManageGroupTest extends DatabaseWebTestCase
{
    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';


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

    private function enableTwoFactor(User $user): void
    {
        $user->setTotpSecret(self::TOTP_SECRET)->setTwoFactorEnabled(true);
        $this->em->flush();
    }

    /** Pass a fresh step-up by confirming a current TOTP code. */
    private function stepUp(): void
    {
        $totp = static::getContainer()->get(\App\TwoFactor\TotpService::class);
        $code = $totp->codeForCounter(self::TOTP_SECRET, intdiv(time(), 30));
        $this->client->request('POST', '/2fa/confirm', ['return' => '/manage/groups', 'code' => $code]);
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

    public function testManagerWithoutTwoFactorIsSentToEnrol(): void
    {
        // rbac:group:manage is a step-up permission: a holder without 2FA is
        // redirected to enrol before they can mutate groups.
        $this->client->loginUser($this->makeUser('boss', ['rbac:group:view', 'rbac:group:manage']));

        $this->client->request('GET', '/manage/groups/new');
        self::assertResponseRedirects('/2fa/setup');
    }

    public function testManagerCanCreateGroupWithPermissions(): void
    {
        $boss = $this->makeUser('boss', ['rbac:group:view', 'rbac:group:manage']);
        $this->enableTwoFactor($boss);
        $this->client->loginUser($boss);
        $this->seededPrivilege('news:manage');

        // rbac:group:manage requires a fresh step-up.
        $this->stepUp();

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
