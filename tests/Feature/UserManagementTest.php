<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\InviteToken;
use App\Entity\Privilege;
use App\Entity\Settings;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserManagementTest extends DatabaseWebTestCase
{
    private function makeUser(string $name, ?string $role, array $privileges, bool $maskPii = false): User
    {
        $group = new Group('G'.$name, 'g-'.$name, $role);
        foreach ($privileges as $p) {
            $priv = new Privilege($p);
            $this->em->persist($priv);
            $group->addPrivilege($priv);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setSettings(new Settings($user));
        $user->addGroup($group);
        $user->completeOnboarding();
        if ($role === 'ROLE_ADMIN') { $user->setTotpSecret('JBSWY3DPEHPK3PXP')->setTwoFactorEnabled(true); }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testAdminInvitesUserAndCreatesToken(): void
    {
        $this->client->loginUser($this->makeUser('boss', 'ROLE_ADMIN', ['global:admin']));

        $crawler = $this->client->request('GET', '/manage/users/invite');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Send invitation')->form();
        $values = $form->getPhpValues();
        $values['user_invite']['username'] = 'newcomer';
        $values['user_invite']['email'] = 'newcomer@example.com';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/manage/users');

        $invited = $this->em->getRepository(User::class)->findOneBy(['name' => 'newcomer']);
        self::assertNotNull($invited);
        self::assertFalse($invited->isOnboardingCompleted());
        self::assertNotNull($this->em->getRepository(InviteToken::class)->findOneBy(['user' => $invited]));
    }

    public function testAcceptingInviteLogsInAndStartsOnboarding(): void
    {
        // Create an invited (not-onboarded) user with a token.
        $user = new User();
        $user->setName('invitee')->setEmail('invitee@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->setSettings(new Settings($user));
        $token = new InviteToken($user, 'invite-token-123');
        $this->em->persist($user);
        $this->em->persist($token);
        $this->em->flush();

        $this->client->request('GET', '/invite/invite-token-123');
        self::assertResponseRedirects('/onboarding');

        // Token consumed.
        $this->em->clear();
        self::assertNull($this->em->getRepository(InviteToken::class)->findOneBy(['token' => 'invite-token-123']));
    }

    public function testExpiredInviteIsRejected(): void
    {
        $user = new User();
        $user->setName('late')->setEmail('late@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $token = new InviteToken($user, 'expired-token');
        $this->em->persist($user);
        $this->em->persist($token);
        $this->em->flush();
        // Force expiry in the past.
        $this->em->getConnection()->executeStatement(
            'UPDATE invite_tokens SET expires_at = :old WHERE token = :t',
            ['old' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:sP'), 't' => 'expired-token'],
        );
        $this->em->clear(); // drop the stale in-memory copy so the controller reads fresh

        $this->client->request('GET', '/invite/expired-token');
        self::assertResponseStatusCodeSame(410);
    }

    public function testPiiMaskingInUserList(): void
    {
        $this->makeUser('victim', null, ['shift:view']);

        // Sub-admin viewer: has user:view but not user:pii:view -> masked.
        $this->client->loginUser($this->makeUser('subadmin', 'ROLE_SUBADMIN', ['user:view']));
        $this->client->request('GET', '/manage/users?q=victim');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('victim@example.com', $this->client->getResponse()->getContent());

        // Global admin: sees raw email.
        $this->client->loginUser($this->makeUser('root', 'ROLE_ADMIN', ['global:admin']));
        $this->client->request('GET', '/manage/users?q=victim');
        self::assertStringContainsString('victim@example.com', $this->client->getResponse()->getContent());
    }
}
