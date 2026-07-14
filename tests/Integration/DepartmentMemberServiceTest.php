<?php

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\User;
use App\Enum\DepartmentPosition;
use App\Repository\UserGroupAssignmentRepository;
use App\Service\DepartmentMemberService;
use App\Service\DepartmentService;
use App\Tests\DatabaseTestCase;

/**
 * Placing a user in a department and moving them between positions. A position is stored as the
 * department-scoped group assignment, so changing it must swap the group rather than stack a second
 * one on top.
 */
final class DepartmentMemberServiceTest extends DatabaseTestCase
{
    private function service(): DepartmentMemberService
    {
        return static::getContainer()->get(DepartmentMemberService::class);
    }

    private function memberships(): UserGroupAssignmentRepository
    {
        return static::getContainer()->get(UserGroupAssignmentRepository::class);
    }

    private function seedGroups(): void
    {
        foreach (DepartmentPosition::cases() as $position) {
            $this->em->persist(new Group($position->label(), $position->groupSlug(), 'ROLE_STAFF'));
        }
    }

    private function makeUser(string $name): User
    {
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function fixture(string $slug): array
    {
        $this->seedGroups();
        $department = new Department(ucfirst($slug), $slug);
        $this->em->persist($department);
        $user = $this->makeUser('member-'.$slug);
        $this->em->flush();

        return [$department, $user];
    }

    public function testAddingStaffMakesTheUserAMember(): void
    {
        [$department, $user] = $this->fixture('audio');

        $this->service()->setPosition($department, $user, DepartmentPosition::STAFF);

        self::assertTrue($this->memberships()->userIsMember($user, $department));
        self::assertSame(DepartmentPosition::STAFF, $this->service()->positionOf($department, $user));
    }

    public function testChangingPositionSwapsTheGroupRatherThanStacking(): void
    {
        [$department, $user] = $this->fixture('light');
        $service = $this->service();

        $service->setPosition($department, $user, DepartmentPosition::STAFF);
        $service->setPosition($department, $user, DepartmentPosition::MANAGER);

        self::assertSame(DepartmentPosition::MANAGER, $service->positionOf($department, $user));

        $held = array_map(
            static fn ($a): string => $a->getGroup()->getSlug(),
            array_filter(
                $this->memberships()->findActiveByDepartment($department),
                static fn ($a): bool => $a->getUser()->getId() === $user->getId(),
            ),
        );
        self::assertSame([DepartmentPosition::MANAGER->groupSlug()], array_values($held));
    }

    public function testDemotionFromManagerToStaffTakesEffect(): void
    {
        [$department, $user] = $this->fixture('stage');
        $service = $this->service();

        $service->setPosition($department, $user, DepartmentPosition::MANAGER);
        $service->setPosition($department, $user, DepartmentPosition::STAFF);

        self::assertSame(DepartmentPosition::STAFF, $service->positionOf($department, $user));

        $members = static::getContainer()->get(DepartmentService::class)->members($department);
        self::assertSame([], $members['managers']);
        self::assertCount(1, $members['staff']);
    }

    public function testRemoveDropsTheMembership(): void
    {
        [$department, $user] = $this->fixture('rigging');

        $this->service()->setPosition($department, $user, DepartmentPosition::SHIFT_MANAGER);
        $this->service()->remove($department, $user);

        self::assertFalse($this->memberships()->userIsMember($user, $department));
        self::assertNull($this->service()->positionOf($department, $user));
    }
}
