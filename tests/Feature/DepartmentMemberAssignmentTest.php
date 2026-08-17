<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Enum\DepartmentPosition;
use App\Service\DepartmentMemberService;
use App\Tests\DatabaseWebTestCase;

/**
 * Non-SSO department staffing: an admin or sub-admin places a user in a department and moves them
 * between positions. SSO-provisioned users are refused, because the identity provider recomputes
 * their position on every sign-in and would undo the change.
 */
final class DepartmentMemberAssignmentTest extends DatabaseWebTestCase
{
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = new Department('Art Show', 'art-show');
        $this->em->persist($this->department);
        foreach (DepartmentPosition::cases() as $position) {
            $this->em->persist(new Group($position->label(), $position->groupSlug(), 'ROLE_STAFF'));
        }
        $this->em->flush();
    }

    private function loginSubAdmin(): void
    {
        $group = new Group('Sub admins', 'subs', 'ROLE_SUBADMIN');
        foreach (['department:view', 'department:member:manage'] as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('subby')->setEmail('subby@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    private function member(string $name, bool $sso = false): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        if ($sso) {
            $user->setAccountSource(User::SOURCE_SSO)->setSsoUserId('sub-'.$name)->setSsoProvider('oidc');
        }
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function positions(): DepartmentMemberService
    {
        return static::getContainer()->get(DepartmentMemberService::class);
    }

    private function showUrl(): string
    {
        return '/departments/'.$this->department->getUuid();
    }

    /** CSRF tokens are bound to the session, so they are read back off the rendered page. */
    private function token(string $formAction): string
    {
        $crawler = $this->client->request('GET', $this->showUrl());

        return $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $formAction))->first()->attr('value');
    }

    public function testSubAdminAddsAMemberAsDepartmentManager(): void
    {
        $this->loginSubAdmin();
        $user = $this->member('alice');

        $action = $this->showUrl().'/members/add';
        $this->client->request('POST', $action, [
            '_token' => $this->token($action),
            'user' => $user->getUuid(),
            'position' => DepartmentPosition::MANAGER->value,
        ]);

        self::assertResponseRedirects($this->showUrl());
        self::assertSame(DepartmentPosition::MANAGER, $this->positions()->positionOf($this->department, $user));
    }

    public function testPositionCanBeChangedAfterwards(): void
    {
        $this->loginSubAdmin();
        $user = $this->member('bob');
        $this->positions()->setPosition($this->department, $user, DepartmentPosition::STAFF);

        $action = $this->showUrl().'/members/'.$user->getUuid().'/position';
        $this->client->request('POST', $action, [
            '_token' => $this->token($action),
            'position' => DepartmentPosition::SHIFT_MANAGER->value,
        ]);

        self::assertResponseRedirects($this->showUrl());
        self::assertSame(DepartmentPosition::SHIFT_MANAGER, $this->positions()->positionOf($this->department, $user));
    }

    /**
     * An SSO-managed user's position is owned by the provider: the form is not offered, and the
     * route refuses the post even when the form is forged, so the UI is not the only guard.
     */
    public function testAnSsoUsersPositionIsNotOfferedOrAccepted(): void
    {
        $this->loginSubAdmin();
        $user = $this->member('carol', sso: true);
        $this->positions()->setPosition($this->department, $user, DepartmentPosition::STAFF);

        $action = $this->showUrl().'/members/'.$user->getUuid().'/position';
        $crawler = $this->client->request('GET', $this->showUrl());
        self::assertCount(0, $crawler->filter(sprintf('form[action="%s"]', $action)), 'no position form is offered');

        $addToken = $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $this->showUrl().'/members/add'))->attr('value');
        $this->client->request('POST', $action, [
            '_token' => $addToken,
            'position' => DepartmentPosition::MANAGER->value,
        ]);

        self::assertSame(
            DepartmentPosition::STAFF,
            $this->positions()->positionOf($this->department, $user),
            'the identity provider owns an SSO position',
        );
    }

    public function testAMemberWithoutTheManagePrivilegeIsDenied(): void
    {
        $group = new Group('Plain staff', 'plain', 'ROLE_STAFF');
        $this->em->persist($group);
        $actor = new User();
        $actor->setName('nobody')->setEmail('nobody@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $actor->addGroup($group);
        $actor->completeOnboarding();
        $this->em->persist($actor);
        $this->em->flush();
        $this->client->loginUser($actor);

        $user = $this->member('dave');
        $this->client->request('POST', $this->showUrl().'/members/add', [
            '_token' => 'forged',
            'user' => $user->getUuid(),
            'position' => DepartmentPosition::STAFF->value,
        ]);

        self::assertResponseStatusCodeSame(403, 'authorization is checked before the token');
        self::assertNull($this->positions()->positionOf($this->department, $user));
    }
}
