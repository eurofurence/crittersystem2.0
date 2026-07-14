<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\DelegatedManagerRequest;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\UserGroupAssignmentRepository;
use App\Service\DelegatedManagerService;
use App\Tests\DatabaseTestCase;

final class DelegatedManagerServiceTest extends DatabaseTestCase
{
    private function service(): DelegatedManagerService
    {
        return static::getContainer()->get(DelegatedManagerService::class);
    }

    private function memberships(): UserGroupAssignmentRepository
    {
        return static::getContainer()->get(UserGroupAssignmentRepository::class);
    }

    private function makeUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function seedGroups(): void
    {
        $this->em->persist(new Group('Shift manager', 'shift-manager', 'ROLE_STAFF'));
        $this->em->persist(new Group('Delegated', 'shift-manager-delegated', 'ROLE_STAFF'));
    }

    public function testRequestApproveGrantsScopedMembership(): void
    {
        $this->seedGroups();
        $department = new Department('Stage', 'stage');
        $this->em->persist($department);
        $subject = $this->makeUser('sam');
        $requester = $this->makeUser('req');
        $this->em->flush();

        $request = $this->service()->request($department, $subject, $requester);
        self::assertTrue($request->isPending());

        $this->service()->approve($request, $requester);

        self::assertSame(DelegatedManagerRequest::STATUS_APPROVED, $request->getStatus());
        self::assertTrue($this->memberships()->userIsMember($subject, $department));
    }

    public function testRejectDoesNotGrant(): void
    {
        $this->seedGroups();
        $department = new Department('Light', 'light');
        $this->em->persist($department);
        $subject = $this->makeUser('leo');
        $approver = $this->makeUser('boss');
        $this->em->flush();

        $request = $this->service()->request($department, $subject, null);
        $this->service()->reject($request, $approver);

        self::assertSame(DelegatedManagerRequest::STATUS_REJECTED, $request->getStatus());
        self::assertFalse($this->memberships()->userIsMember($subject, $department));
    }

}
