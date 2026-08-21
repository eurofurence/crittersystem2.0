<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserGroupAssignment;
use App\Service\DepartmentContactResolver;
use App\Tests\DatabaseTestCase;

/**
 * "Who do I ask about this department's shifts?"
 *
 * The answer has to be short enough to act on at a desk. An event-wide grant is answerable for every
 * department at once and an administrator is answerable for everything, so neither is a useful
 * contact while the department has somebody of its own - and both would drown the real planner.
 */
final class DepartmentContactResolverTest extends DatabaseTestCase
{
    private DepartmentContactResolver $resolver;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = static::getContainer()->get(DepartmentContactResolver::class);
        $this->department = new Department('Demo Dept', 'demo-'.bin2hex(random_bytes(3)));
        $this->em->persist($this->department);
        $this->em->flush();
    }

    public function testScopedManagersAreReturnedByUsername(): void
    {
        $this->manager('zara', $this->department);
        $this->manager('alice', $this->department);

        $names = array_map(static fn (User $u): string => $u->getName(), $this->resolver->managersOf($this->department));

        self::assertSame(['alice', 'zara'], $names);
    }

    /** An event-wide holder is answerable, but only worth naming when nobody owns the department. */
    public function testEventWideHoldersAreOnlyOfferedWhenTheDepartmentHasNobody(): void
    {
        $this->manager('eventwide', null);

        self::assertSame(['eventwide'], $this->names($this->resolver->managersOf($this->department)));

        $this->manager('owner', $this->department);

        self::assertSame(['owner'], $this->names($this->resolver->managersOf($this->department)));
    }

    /** global:admin satisfies every check, so an admin would otherwise be listed on every shift. */
    public function testAdministratorsAreNeverListed(): void
    {
        $this->manager('theadmin', $this->department, 'ROLE_ADMIN');
        $this->manager('owner', $this->department);

        self::assertSame(['owner'], $this->names($this->resolver->managersOf($this->department)));
    }

    public function testAnExpiredAssignmentDoesNotMakeSomebodyAContact(): void
    {
        $lapsed = $this->manager('lapsed', $this->department);
        foreach ($this->em->getRepository(UserGroupAssignment::class)->findBy(['user' => $lapsed]) as $assignment) {
            $assignment->setExpiresAt(new \DateTimeImmutable('-1 day'));
        }
        $this->em->flush();

        self::assertSame([], $this->resolver->managersOf($this->department));
    }

    /** Holding neither privilege is not a qualification, whatever else the group carries. */
    public function testSomebodyWithoutThesePrivilegesIsNotAContact(): void
    {
        $this->manager('browser', $this->department, 'ROLE_STAFF', ['shift:view']);

        self::assertSame([], $this->resolver->managersOf($this->department));
    }

    /**
     * @param string[] $privileges
     */
    private function manager(
        string $name,
        ?Department $scope,
        string $role = 'ROLE_STAFF',
        array $privileges = ['shift:manage'],
    ): User {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Grp '.$suffix, 'grp-'.$suffix, $role);
        foreach ($privileges as $privilegeName) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $privilegeName])
                ?? new Privilege($privilegeName);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setPassword('x')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->completeOnboarding();
        $this->em->persist(new UserGroupAssignment($user, $group, $scope));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param User[] $users
     *
     * @return string[]
     */
    private function names(array $users): array
    {
        return array_map(static fn (User $u): string => $u->getName(), $users);
    }
}
