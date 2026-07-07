<?php

namespace App\Tests\Unit\Security;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Security\PrivilegeVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class PrivilegeVoterTest extends TestCase
{
    private PrivilegeVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PrivilegeVoter();
    }

    /** @param string[] $privilegeNames */
    private function group(string $slug, array $privilegeNames): Group
    {
        $group = new Group(ucfirst($slug), $slug);
        foreach ($privilegeNames as $name) {
            $group->addPrivilege(new Privilege($name));
        }

        return $group;
    }

    /** @param string[] $privilegeNames */
    private function userWith(array $privilegeNames): User
    {
        $user = new User();
        $user->addGroup($this->group('volunteer', $privilegeNames));

        return $user;
    }

    private function vote(?User $user, string $attribute, mixed $subject = null): int
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $this->voter->vote($token, $subject, [$attribute]);
    }

    public function testGrantsWhenUserHasPrivilege(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->userWith(['user:view']), 'user:view'),
        );
    }

    public function testDeniesWhenUserLacksPrivilege(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->userWith(['news:view']), 'user:view'),
        );
    }

    public function testSuperPrivilegeGrantsEverything(): void
    {
        $admin = $this->userWith(['global:admin']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, 'location:manage'));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, 'volunteertype:manage'));
    }

    public function testAbstainsOnNonPrivilegeAttributes(): void
    {
        $user = $this->userWith(['user:view']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($user, 'ROLE_ADMIN'));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($user, 'SOMETHING_UNKNOWN'));
    }

    public function testDeniesAnonymousUserForKnownPrivilege(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, 'user:view'));
    }

    public function testScopedPermissionWithoutSubjectIgnoresScope(): void
    {
        $user = new User();
        $deptA = new Department('Art Show', 'art-show');
        $user->assignGroup($this->group('department-manager', ['department:manage']), $deptA);

        // No subject -> "can reach the area at all".
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($user, 'department:manage'));
    }

    public function testDepartmentScopeGrantsOwnDepartmentAndDeniesOthers(): void
    {
        $user = new User();
        $deptA = new Department('Art Show', 'art-show');
        $deptB = new Department('Security', 'security');
        $user->assignGroup($this->group('department-manager', ['department:manage']), $deptA);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($user, 'department:manage', $deptA));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($user, 'department:manage', $deptB));
    }

    public function testUnscopedAssignmentGrantsAnyDepartment(): void
    {
        $user = new User();
        $deptB = new Department('Security', 'security');
        // Unscoped (department null) grant of a scoped permission.
        $user->addGroup($this->group('shift-manager', ['department:manage']));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($user, 'department:manage', $deptB));
    }

    public function testExpiredAssignmentIsIgnored(): void
    {
        $user = new User();
        $user->assignGroup($this->group('delegated', ['shift:manage']), null, new \DateTimeImmutable('-1 hour'));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($user, 'shift:manage'));
    }
}
