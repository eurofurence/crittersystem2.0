<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserPrivilegesTest extends TestCase
{
    /** @param string[] $privilegeNames */
    private function group(int $id, string $name, array $privilegeNames): Group
    {
        $group = new Group($id, $name, strtolower(str_replace(' ', '-', $name)));
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
        self::assertFalse($user->hasPrivilege('admin'));
    }

    public function testPrivilegesAreTheUnionAcrossGroupsAndDeduplicated(): void
    {
        $user = new User();
        $user->addGroup($this->group(20, 'Volunteer', ['news', 'user_shifts']));
        $user->addGroup($this->group(85, 'News Admin', ['news', 'admin_news']));

        self::assertTrue($user->hasPrivilege('news'));
        self::assertTrue($user->hasPrivilege('admin_news'));
        self::assertTrue($user->hasAnyPrivilege(['missing', 'user_shifts']));
        self::assertFalse($user->hasAnyPrivilege(['missing', 'none']));

        $names = $user->getPrivilegeNames();
        sort($names);
        self::assertSame(['admin_news', 'news', 'user_shifts'], $names);
    }

    public function testAdminPrivilegeMapsToAdminAndStaffRoles(): void
    {
        $user = new User();
        $user->addGroup($this->group(90, 'Developer', ['admin', 'user.type.admin']));

        $roles = $user->getRoles();
        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
        self::assertContains('ROLE_STAFF', $roles);
    }

    public function testStaffFlagMapsToStaffRoleOnly(): void
    {
        $user = new User();
        $user->addGroup($this->group(60, 'Shift Coordinator', ['user.type.staff']));

        $roles = $user->getRoles();
        self::assertContains('ROLE_STAFF', $roles);
        self::assertNotContains('ROLE_ADMIN', $roles);
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
        $group = $this->group(20, 'Volunteer', ['news']);
        $user->addGroup($group);
        $user->addGroup($group);

        self::assertCount(1, $user->getGroups());
    }
}
