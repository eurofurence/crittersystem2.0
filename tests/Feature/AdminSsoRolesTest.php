<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Tests\DatabaseWebTestCase;

/**
 * The SSO department-role page exposes raw identity-provider role IDs, so both of its gates matter:
 * sub-admins must not reach it at all, and an admin must have cleared two-factor step-up first.
 */
final class AdminSsoRolesTest extends DatabaseWebTestCase
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

    public function testSubAdminCannotReachThePage(): void
    {
        $this->client->loginUser($this->user('subby', 'ROLE_SUBADMIN', 'global:dashboard'));

        $this->client->request('GET', '/admin/sso-roles');

        self::assertResponseStatusCodeSame(403, 'the /admin firewall rule demands ROLE_ADMIN');
    }

    public function testAdminWithoutTwoFactorIsSentToEnrol(): void
    {
        $this->client->loginUser($this->user('rootless', 'ROLE_ADMIN', 'global:admin'));

        $this->client->request('GET', '/admin/sso-roles');

        self::assertResponseRedirects('/2fa/setup');
    }

    public function testAdminWithFreshStepUpCanSaveTheRoleIds(): void
    {
        $admin = $this->user('root', 'ROLE_ADMIN', 'global:admin');
        $admin->setTotpSecret('SECRET')->setTwoFactorEnabled(true);
        $this->em->flush();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/dashboard');
        $session = $this->client->getRequest()->getSession();
        $session->set('_mfa_verified_at', time());
        $session->save();

        $crawler = $this->client->request('GET', '/admin/sso-roles');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->selectButton('Save')->form([
            'sso_role[departmentManagerRole]' => 'IDP-DM',
            'sso_role[shiftManagerRole]' => 'IDP-SM',
        ]));

        self::assertResponseRedirects('/admin/sso-roles');

        $store = static::getContainer()->get(EventConfigStore::class);
        self::assertSame('IDP-DM', $store->get(EventConfigStore::KEY_SSO_ROLE_DEPARTMENT_MANAGER));
        self::assertSame('IDP-SM', $store->get(EventConfigStore::KEY_SSO_ROLE_SHIFT_MANAGER));
    }
}
