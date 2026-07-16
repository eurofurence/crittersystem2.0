<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;

/**
 * The registration-API endpoint is edited on /admin/sso, which is admin-only and step-up guarded
 * (config:sso). Saving it persists into the event-config store.
 */
final class AdminSsoRegistrationApiTest extends DatabaseWebTestCase
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

    public function testAdminWithoutTwoFactorIsSentToEnrol(): void
    {
        $this->client->loginUser($this->user('rootless', 'ROLE_ADMIN', 'global:admin'));
        $this->client->request('GET', '/admin/sso');
        self::assertResponseRedirects('/2fa/setup');
    }

    public function testAdminWithFreshStepUpCanSaveTheEndpoint(): void
    {
        $admin = $this->user('root', 'ROLE_ADMIN', 'global:admin');
        $admin->setTotpSecret('SECRET')->setTwoFactorEnabled(true);
        $this->em->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/dashboard');
        $session = $this->client->getRequest()->getSession();
        $session->set('_mfa_verified_at', time());
        $session->save();

        $crawler = $this->client->request('GET', '/admin/sso');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save')->form([
            'registration_api[apiUrl]' => 'https://reg.example.org/api/me',
        ]));

        self::assertResponseRedirects('/admin/sso');

        $store = static::getContainer()->get(EventConfigStore::class);
        self::assertSame('https://reg.example.org/api/me', $store->get(EventConfigStore::KEY_SSO_BADGE_API_URL));
    }
}
