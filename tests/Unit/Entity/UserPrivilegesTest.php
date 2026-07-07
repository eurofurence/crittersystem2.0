<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserPrivilegesTest extends TestCase
{
    /** @param string[] $privilegeNames */
    private function group(string $name, array $privilegeNames, ?string $role = null): Group
    {
        $group = new Group($name, strtolower(str_replace(' ', '-', $name)), $role);
        foreach ($privilegeNames as $privilegeName) {
            $group->addPrivilege(new Privilege($privilegeName));
        }

        return $group;
    }

    public function testNoGroupsYieldsOnlyRoleUser(): void
    {
        $user = new User();

        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertSame([], $user->getPrivilegeNames());
        self::assertFalse($user->hasPrivilege('global:admin'));
    }

    public function testPrivilegesAreTheUnionAcrossGroupsAndDeduplicated(): void
    {
        $user = new User();
        $user->addGroup($this->group('Volunteer', ['news:view', 'shift:view']));
        $user->addGroup($this->group('News Admin', ['news:view', 'news:manage']));

        self::assertTrue($user->hasPrivilege('news:view'));
        self::assertTrue($user->hasPrivilege('news:manage'));
        self::assertTrue($user->hasAnyPrivilege(['missing', 'shift:view']));
        self::assertFalse($user->hasAnyPrivilege(['missing', 'none']));

        $names = $user->getPrivilegeNames();
        sort($names);
        self::assertSame(['news:manage', 'news:view', 'shift:view'], $names);
    }

    public function testGlobalAdminGroupYieldsAdminRole(): void
    {
        $user = new User();
        $user->addGroup($this->group('Global admin', ['global:admin'], 'ROLE_ADMIN'));

        $roles = $user->getRoles();
        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
        self::assertTrue($user->isStaff());
    }

    public function testStaffGroupYieldsStaffRoleOnly(): void
    {
        $user = new User();
        $user->addGroup($this->group('Shift manager', ['shift:manage'], 'ROLE_STAFF'));

        $roles = $user->getRoles();
        self::assertContains('ROLE_STAFF', $roles);
        self::assertNotContains('ROLE_ADMIN', $roles);
    }

    public function testPlainGroupGrantsNoRoleBeyondUser(): void
    {
        $user = new User();
        $user->addGroup($this->group('Volunteer', ['shift:view']));

        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertFalse($user->isStaff());
    }

    public function testExpiredAssignmentGrantsNothing(): void
    {
        $user = new User();
        $user->assignGroup(
            $this->group('Delegated', ['shift:manage'], 'ROLE_STAFF'),
            null,
            new \DateTimeImmutable('-1 hour'),
        );

        self::assertFalse($user->hasPrivilege('shift:manage'));
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testUserIdentifierIsTheUsername(): void
    {
        $user = new User();
        $user->setName('alice');

        self::assertSame('alice', $user->getUserIdentifier());
    }

    public function testAddingTheSameGroupTwiceKeepsOneEntry(): void
    {
        $user = new User();
        $group = $this->group('Volunteer', ['news:view']);
        $user->addGroup($group);
        $user->addGroup($group);

        self::assertCount(1, $user->getGroups());
    }
}
