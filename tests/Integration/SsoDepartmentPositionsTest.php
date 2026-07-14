<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Enum\DepartmentPosition;
use App\Service\DepartmentMemberService;
use App\Service\EventConfigStore;
use App\Sso\SsoClaims;
use App\Sso\SsoMappingImporter;
use App\Sso\SsoUserProvisioner;
use App\Tests\DatabaseTestCase;

/**
 * The identity provider names two global roles and a department; the combination decides the user's
 * position in that department. Protects the precedence rule (department manager beats shift manager
 * beats staff) and the demotion path — a role withdrawn at the provider must take effect on the next
 * sign-in, not linger.
 */
final class SsoDepartmentPositionsTest extends DatabaseTestCase
{
    private const MANAGER_ROLE = 'IDP-ROLE-DEPT-MANAGER';
    private const SHIFT_MANAGER_ROLE = 'IDP-ROLE-SHIFT-MANAGER';
    private const DEPARTMENT_ROLE = 'IDP-GROUP-ART-SHOW';

    private function seed(): void
    {
        $this->em->persist(new Department('Art Show', 'art-show'));
        foreach (DepartmentPosition::cases() as $position) {
            $this->em->persist(new Group($position->label(), $position->groupSlug(), 'ROLE_STAFF'));
        }
        $this->em->flush();

        static::getContainer()->get(SsoMappingImporter::class)->import([[
            'id' => self::DEPARTMENT_ROLE, 'name' => 'Art Show', 'slug' => 'art-show', 'department' => 'art-show',
        ]]);

        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_SSO_ROLE_DEPARTMENT_MANAGER, self::MANAGER_ROLE);
        $config->set(EventConfigStore::KEY_SSO_ROLE_SHIFT_MANAGER, self::SHIFT_MANAGER_ROLE);
        $config->flush();
    }

    /** @param string[] $roles */
    private function signIn(array $roles): ?DepartmentPosition
    {
        $user = static::getContainer()->get(SsoUserProvisioner::class)->provision(
            new SsoClaims('sub-1', 'sso@example.com', 'ssouser', 'Sso User', $roles),
        );

        $department = $this->em->getRepository(Department::class)->findOneBy(['slug' => 'art-show']);

        return static::getContainer()->get(DepartmentMemberService::class)->positionOf($department, $user);
    }

    public function testDepartmentRoleAloneIsStaff(): void
    {
        $this->seed();

        self::assertSame(DepartmentPosition::STAFF, $this->signIn([self::DEPARTMENT_ROLE]));
    }

    public function testManagerRolePlusDepartmentRoleIsDepartmentManager(): void
    {
        $this->seed();

        self::assertSame(
            DepartmentPosition::MANAGER,
            $this->signIn([self::DEPARTMENT_ROLE, self::MANAGER_ROLE]),
        );
    }

    public function testShiftManagerRolePlusDepartmentRoleIsShiftManager(): void
    {
        $this->seed();

        self::assertSame(
            DepartmentPosition::SHIFT_MANAGER,
            $this->signIn([self::DEPARTMENT_ROLE, self::SHIFT_MANAGER_ROLE]),
        );
    }

    public function testHoldingBothRolesResolvesToDepartmentManager(): void
    {
        $this->seed();

        self::assertSame(
            DepartmentPosition::MANAGER,
            $this->signIn([self::DEPARTMENT_ROLE, self::SHIFT_MANAGER_ROLE, self::MANAGER_ROLE]),
        );
    }

    public function testAManagerRoleWithoutADepartmentRoleGrantsNothing(): void
    {
        $this->seed();

        self::assertNull($this->signIn([self::MANAGER_ROLE]));
    }

    public function testWithdrawingTheManagerRoleDemotesOnTheNextSignIn(): void
    {
        $this->seed();

        self::assertSame(
            DepartmentPosition::MANAGER,
            $this->signIn([self::DEPARTMENT_ROLE, self::MANAGER_ROLE]),
        );

        self::assertSame(
            DepartmentPosition::STAFF,
            $this->signIn([self::DEPARTMENT_ROLE]),
            'the position is reconciled on every login, not merely added to',
        );
    }

    public function testAnUnconfiguredRoleNeverMatches(): void
    {
        $this->seed();
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_SSO_ROLE_DEPARTMENT_MANAGER, '');
        $config->flush();

        self::assertSame(
            DepartmentPosition::STAFF,
            $this->signIn([self::DEPARTMENT_ROLE, self::MANAGER_ROLE]),
        );
    }
}
