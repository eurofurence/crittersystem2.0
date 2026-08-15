<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\InviteToken;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class UserIdentityManagementTest extends DatabaseWebTestCase
{
    private function makeUser(string $name, ?string $role, array $privileges, bool $onboarded = true): User
    {
        $group = new Group('G'.$name, 'g-'.$name, $role);
        foreach ($privileges as $permission) {
            $privilege = new Privilege($permission);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)
            ->setEmail($name.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword($hasher->hashPassword($user, 'secret123'))
            ->setSettings(new Settings($user));
        $user->addGroup($group);
        if ($onboarded) {
            $user->completeOnboarding();
        }
        if ($role === 'ROLE_ADMIN') {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP')->setTwoFactorEnabled(true);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function csrf(string $id): string
    {
        return (string) static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($id);
    }

    private function markMfaFresh(): void
    {
        $session = $this->client->getRequest()->getSession();
        $session->set('_mfa_verified_at', time());
        $session->save();
    }

    public function testSubAdminCannotChangeEmailThroughDedicatedEndpoint(): void
    {
        $target = $this->makeUser('target', null, []);
        $subAdmin = $this->makeUser('subadmin', 'ROLE_SUBADMIN', ['user:view', 'user:edit']);
        $this->client->loginUser($subAdmin);

        $this->client->request('POST', '/manage/users/'.$target->getUuid().'/email', [
            'email' => 'changed@example.com',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('target@example.com', $target->getEmail());
    }

    public function testGeneralEditCannotSmuggleEmailChange(): void
    {
        $target = $this->makeUser('target', null, []);
        $subAdmin = $this->makeUser('subadmin', 'ROLE_SUBADMIN', ['user:view', 'user:edit']);
        $this->client->loginUser($subAdmin);

        $this->client->request('POST', '/manage/users/'.$target->getUuid().'/edit', [
            'email' => 'smuggled@example.com',
            'active' => '1',
        ]);

        self::assertResponseRedirects('/manage/users');
        self::assertSame('target@example.com', $target->getEmail());
    }

    public function testGlobalAdminEmailCorrectionRequiresFreshStepUp(): void
    {
        $target = $this->makeUser('target', null, []);
        $admin = $this->makeUser('admin', 'ROLE_ADMIN', ['global:admin']);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/manage/users/'.$target->getUuid().'/edit');

        $this->client->request('POST', '/manage/users/'.$target->getUuid().'/email', [
            '_token' => $this->csrf('change-email'.$target->getId()),
            'email' => 'changed-without-mfa@example.com',
        ]);

        self::assertResponseRedirects();
        self::assertSame('target@example.com', $target->getEmail());
    }

    public function testGlobalAdminCanCorrectManualAccountEmailAfterStepUp(): void
    {
        $target = $this->makeUser('target', null, []);
        $admin = $this->makeUser('admin', 'ROLE_ADMIN', ['global:admin']);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/manage/users/'.$target->getUuid().'/edit');
        $this->markMfaFresh();

        $this->client->request('POST', '/manage/users/'.$target->getUuid().'/email', [
            '_token' => $this->csrf('change-email'.$target->getId()),
            'email' => 'corrected@example.com',
        ]);

        self::assertResponseRedirects('/manage/users/'.$target->getUuid().'/edit');
        self::assertSame('corrected@example.com', $target->getEmail());
    }

    public function testSsoEmailCannotBeOverriddenByGlobalAdmin(): void
    {
        $target = $this->makeUser('sso-user', null, []);
        $target->setAccountSource(User::SOURCE_SSO)->setSsoUserId('subject-1')->setSsoProvider('oidc');
        $this->em->flush();

        $admin = $this->makeUser('admin', 'ROLE_ADMIN', ['global:admin']);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/manage/users/'.$target->getUuid().'/edit');
        $this->markMfaFresh();

        $this->client->request('POST', '/manage/users/'.$target->getUuid().'/email', [
            '_token' => $this->csrf('change-email'.$target->getId()),
            'email' => 'override@example.com',
        ]);

        self::assertResponseRedirects('/manage/users/'.$target->getUuid().'/edit');
        self::assertSame('sso-user@example.com', $target->getEmail());
    }

    public function testSubAdminCanReissueInviteForOrdinaryManualAccount(): void
    {
        $target = $this->makeUser('invitee', null, [], false);
        $invite = new InviteToken($target, 'old-invitation-token');
        $this->em->persist($invite);
        $this->em->flush();

        $subAdmin = $this->makeUser('subadmin', 'ROLE_SUBADMIN', ['user:view', 'user:create']);
        $this->client->loginUser($subAdmin);
        $this->client->request('GET', '/manage/users');

        $this->client->request('POST', '/manage/users/'.$target->getUuid().'/resend-invite', [
            '_token' => $this->csrf('resend-invite'.$target->getId()),
        ]);

        self::assertResponseRedirects('/manage/users');
        $this->em->refresh($invite);
        self::assertNotSame('old-invitation-token', $invite->getToken());
        self::assertNull($this->em->getRepository(InviteToken::class)->findOneBy(['token' => 'old-invitation-token']));
        self::assertFalse($invite->isExpired());
    }

    public function testSubAdminCannotReissueInviteForAdminAccount(): void
    {
        $target = $this->makeUser('otheradmin', 'ROLE_ADMIN', ['global:admin'], false);
        $invite = new InviteToken($target, 'admin-invitation-token');
        $this->em->persist($invite);
        $this->em->flush();

        $subAdmin = $this->makeUser('subadmin', 'ROLE_SUBADMIN', ['user:view', 'user:create']);
        $this->client->loginUser($subAdmin);
        $this->client->request('GET', '/manage/users');

        $this->client->request('POST', '/manage/users/'.$target->getUuid().'/resend-invite', [
            '_token' => $this->csrf('resend-invite'.$target->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('admin-invitation-token', $invite->getToken());
    }

    public function testCorrectingPendingManualEmailAlsoRotatesInvitation(): void
    {
        $target = $this->makeUser('pending', null, [], false);
        $invite = new InviteToken($target, 'mistyped-address-token');
        $this->em->persist($invite);
        $this->em->flush();

        $admin = $this->makeUser('admin', 'ROLE_ADMIN', ['global:admin']);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/manage/users/'.$target->getUuid().'/edit');
        $this->markMfaFresh();

        $this->client->request('POST', '/manage/users/'.$target->getUuid().'/email', [
            '_token' => $this->csrf('change-email'.$target->getId()),
            'email' => 'right-address@example.com',
        ]);

        self::assertResponseRedirects('/manage/users/'.$target->getUuid().'/edit');
        self::assertSame('right-address@example.com', $target->getEmail());
        $this->em->refresh($invite);
        self::assertNotSame('mistyped-address-token', $invite->getToken());
        self::assertNull($this->em->getRepository(InviteToken::class)->findOneBy(['token' => 'mistyped-address-token']));
    }
}
