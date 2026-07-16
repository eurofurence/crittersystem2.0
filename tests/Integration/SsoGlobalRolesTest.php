<?php

namespace App\Tests\Integration;

use App\Entity\Group;
use App\Entity\User;
use App\Service\EventConfigStore;
use App\Sso\SsoClaims;
use App\Sso\SsoUserProvisioner;
use App\Tests\DatabaseTestCase;

/**
 * Two identity-provider role IDs grant the app-wide global-admin / sub-admin groups. Protects the
 * precedence rule (global admin beats sub admin), the demotion path (a role withdrawn at the provider
 * takes effect on the next sign-in), and the guard that an unconfigured role leaves its group alone.
 */
final class SsoGlobalRolesTest extends DatabaseTestCase
{
    private const GLOBAL_ADMIN_ROLE = 'IDP-ROLE-GLOBAL-ADMIN';
    private const SUB_ADMIN_ROLE = 'IDP-ROLE-SUB-ADMIN';

    private function seedGroups(): void
    {
        $this->em->persist(new Group('Global admin', 'global-admin', 'ROLE_ADMIN'));
        $this->em->persist(new Group('Sub admin', 'sub-admin', 'ROLE_SUBADMIN'));
        $this->em->flush();
    }

    private function configure(?string $globalAdmin, ?string $subAdmin): void
    {
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_SSO_ROLE_GLOBAL_ADMIN, $globalAdmin ?? '');
        $config->set(EventConfigStore::KEY_SSO_ROLE_SUB_ADMIN, $subAdmin ?? '');
        $config->flush();
    }

    /**
     * @param string[] $roles
     *
     * @return string[] the slugs of the groups the user holds after signing in
     */
    private function signIn(array $roles): array
    {
        $user = static::getContainer()->get(SsoUserProvisioner::class)->provision(
            new SsoClaims('sub-1', 'sso@example.com', 'ssouser', 'Sso User', $roles),
        );

        return array_map(static fn (Group $g): string => $g->getSlug(), $user->getGroups()->toArray());
    }

    public function testGlobalAdminRoleGrantsGlobalAdmin(): void
    {
        $this->seedGroups();
        $this->configure(self::GLOBAL_ADMIN_ROLE, self::SUB_ADMIN_ROLE);

        self::assertContains('global-admin', $this->signIn([self::GLOBAL_ADMIN_ROLE]));
    }

    public function testSubAdminRoleGrantsSubAdmin(): void
    {
        $this->seedGroups();
        $this->configure(self::GLOBAL_ADMIN_ROLE, self::SUB_ADMIN_ROLE);

        self::assertContains('sub-admin', $this->signIn([self::SUB_ADMIN_ROLE]));
    }

    public function testHoldingBothRolesResolvesToGlobalAdminOnly(): void
    {
        $this->seedGroups();
        $this->configure(self::GLOBAL_ADMIN_ROLE, self::SUB_ADMIN_ROLE);

        $slugs = $this->signIn([self::SUB_ADMIN_ROLE, self::GLOBAL_ADMIN_ROLE]);
        self::assertContains('global-admin', $slugs);
        self::assertNotContains('sub-admin', $slugs, 'global admin wins over sub admin');
    }

    public function testWithdrawingTheGlobalAdminRoleDemotesOnNextSignIn(): void
    {
        $this->seedGroups();
        $this->configure(self::GLOBAL_ADMIN_ROLE, self::SUB_ADMIN_ROLE);

        self::assertContains('global-admin', $this->signIn([self::GLOBAL_ADMIN_ROLE]));
        self::assertNotContains(
            'global-admin',
            $this->signIn([]),
            'the grant is reconciled on every login, not merely added to',
        );
    }

    public function testDroppingToSubAdminReplacesGlobalAdmin(): void
    {
        $this->seedGroups();
        $this->configure(self::GLOBAL_ADMIN_ROLE, self::SUB_ADMIN_ROLE);

        self::assertContains('global-admin', $this->signIn([self::GLOBAL_ADMIN_ROLE]));

        $slugs = $this->signIn([self::SUB_ADMIN_ROLE]);
        self::assertContains('sub-admin', $slugs);
        self::assertNotContains('global-admin', $slugs);
    }

    public function testAnUnconfiguredRoleLeavesItsGroupUntouched(): void
    {
        $this->seedGroups();
        // Only the global-admin role ID is configured; the app has no opinion on the sub-admin group.
        $this->configure(self::GLOBAL_ADMIN_ROLE, null);

        $this->signIn([]);
        $user = $this->em->getRepository(User::class)->findOneBy(['ssoUserId' => 'sub-1']);
        $user->addGroup($this->em->getRepository(Group::class)->findOneBy(['slug' => 'sub-admin']));
        $this->em->flush();

        self::assertContains('sub-admin', $this->signIn([]), 'a group with no configured role ID is never stripped');
    }
}
