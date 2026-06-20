<?php

namespace App\Tests\Unit\Security;

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
    private function userWith(array $privilegeNames): User
    {
        $group = new Group(20, 'Volunteer', 'volunteer');
        foreach ($privilegeNames as $name) {
            $group->addPrivilege(new Privilege($name));
        }
        $user = new User();
        $user->addGroup($group);

        return $user;
    }

    private function vote(?User $user, string $attribute): int
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $this->voter->vote($token, null, [$attribute]);
    }

    public function testGrantsWhenUserHasPrivilege(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($this->userWith(['admin_user']), 'admin_user'),
        );
    }

    public function testDeniesWhenUserLacksPrivilege(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->userWith(['news']), 'admin_user'),
        );
    }

    public function testAdminPrivilegeGrantsAnyPrivilege(): void
    {
        $admin = $this->userWith(['admin']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, 'admin_rooms'));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, 'admin_volunteer_types'));
    }

    public function testAbstainsOnNonPrivilegeAttributes(): void
    {
        $user = $this->userWith(['admin_user']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($user, 'ROLE_ADMIN'));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($user, 'SOMETHING_UNKNOWN'));
    }

    public function testDeniesAnonymousUserForKnownPrivilege(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, 'admin_user'));
    }
}
