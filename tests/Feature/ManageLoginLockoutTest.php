<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\LoginLockout;
use App\Entity\Privilege;
use App\Entity\User;
use App\Repository\LoginLockoutRepository;
use App\Tests\DatabaseWebTestCase;

/**
 * The lockout management screen: admins can see what the throttle is currently refusing and lift it,
 * because a volunteer locked out mid-event cannot always wait half an hour.
 */
final class ManageLoginLockoutTest extends DatabaseWebTestCase
{
    private function user(string $name, ?string $role, string ...$privileges): User
    {
        $group = new Group(ucfirst($name), $name.'-'.bin2hex(random_bytes(2)), $role);
        foreach ($privileges as $privilege) {
            $p = new Privilege($privilege);
            $this->em->persist($p);
            $group->addPrivilege($p);
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

    private function steppedUpAdmin(): User
    {
        $admin = $this->user('root', 'ROLE_ADMIN', 'global:admin');
        $admin->setTotpSecret('SECRET')->setTwoFactorEnabled(true);
        $this->em->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/dashboard');
        $session = $this->client->getRequest()->getSession();
        $session->set('_mfa_verified_at', time());
        $session->save();

        return $admin;
    }

    private function lockout(string $scope = LoginLockout::SCOPE_ACCOUNT, string $subject = 'target'): LoginLockout
    {
        $now = new \DateTimeImmutable();
        $lockout = new LoginLockout($scope, $subject, $now, $now->modify('+30 minutes'), 3, 2);
        $this->em->persist($lockout);
        $this->em->flush();

        return $lockout;
    }

    public function testAVolunteerCannotReachTheScreen(): void
    {
        $this->client->loginUser($this->user('volunteer', null));
        $this->client->request('GET', '/manage/login-lockouts');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheScreenListsActiveLockouts(): void
    {
        $this->steppedUpAdmin();
        $this->lockout();

        $this->client->request('GET', '/manage/login-lockouts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'target');
    }

    public function testAnExpiredLockoutIsNotListed(): void
    {
        $this->steppedUpAdmin();
        $past = new \DateTimeImmutable('-2 hours');
        $this->em->persist(new LoginLockout(LoginLockout::SCOPE_ACCOUNT, 'ancient', $past, $past->modify('+30 minutes'), 3, 2));
        $this->em->flush();

        $this->client->request('GET', '/manage/login-lockouts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'ancient');
    }

    public function testTheHashedSubjectOfAnAddressLockoutIsNeverShown(): void
    {
        $this->steppedUpAdmin();
        $hash = str_repeat('a', 64);
        $this->lockout(LoginLockout::SCOPE_IP, $hash);

        $this->client->request('GET', '/manage/login-lockouts');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($hash, (string) $this->client->getResponse()->getContent());
    }

    public function testAnAdminCanLiftALockout(): void
    {
        $this->steppedUpAdmin();
        $lockout = $this->lockout();

        $crawler = $this->client->request('GET', '/manage/login-lockouts');
        $this->client->submit($crawler->selectButton('Lift')->form());

        self::assertResponseRedirects('/manage/login-lockouts');

        $this->em->clear();
        self::assertNull(static::getContainer()->get(LoginLockoutRepository::class)->findOneFor(
            $lockout->getScope(),
            $lockout->getSubject(),
        ));
    }

    public function testLiftingRequiresAValidCsrfToken(): void
    {
        $this->steppedUpAdmin();
        $lockout = $this->lockout();

        $this->client->request('POST', '/manage/login-lockouts/'.$lockout->getUuid().'/release', ['_token' => 'forged']);

        self::assertResponseRedirects('/manage/login-lockouts');

        $this->em->clear();
        self::assertNotNull(static::getContainer()->get(LoginLockoutRepository::class)->findOneFor(
            $lockout->getScope(),
            $lockout->getSubject(),
        ));
    }
}
