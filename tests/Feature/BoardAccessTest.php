<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\Shift;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Department scoping on the live operations board.
 *
 * board:view is department-scoped, and PrivilegeVoter applies that scope only when handed the
 * resource, so the class-level attribute grants nothing more than reachability. Every route that
 * names a department has to re-check with it, and a department the caller may not see must answer
 * 404 rather than 403 so the board never confirms which departments exist.
 */
final class BoardAccessTest extends DatabaseWebTestCase
{
    private function department(string $name, bool $organizational = false): Department
    {
        $department = new Department($name, strtolower($name).'-'.bin2hex(random_bytes(3)));
        $department->setOrganizational($organizational);
        $this->em->persist($department);

        return $department;
    }

    /** @param list<string> $privileges */
    private function user(array $privileges, ?Department $scope): User
    {
        $suffix = bin2hex(random_bytes(4));
        $group = new Group('Board '.$suffix, 'board-'.$suffix, 'ROLE_STAFF');
        foreach ($privileges as $name) {
            $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => $name]) ?? new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $user = new User();
        $user->setName('board-'.$suffix)->setEmail('board-'.$suffix.'@example.com')
            ->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'secret123'));
        $user->completeOnboarding();
        $this->em->persist($user->assignGroup($group, $scope));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function shift(Department $department, string $starts, string $ends): Shift
    {
        $shift = (new Shift())->setTitle('Gate')
            ->setStartsAt(new \DateTimeImmutable($starts))
            ->setEndsAt(new \DateTimeImmutable($ends))
            ->setDepartment($department);
        $this->em->persist($shift);
        $this->em->flush();

        return $shift;
    }

    public function testRailListsOnlyTheDepartmentsTheUserIsScopedTo(): void
    {
        $mine = $this->department('Logistics');
        $theirs = $this->department('Security');
        $this->shift($mine, 'today 10:00', 'today 12:00');

        $this->client->loginUser($this->user(['board:view'], $mine));
        $crawler = $this->client->request('GET', '/board/'.$mine->getUuid().'/'.date('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Logistics', $crawler->filter('[data-board-rail="departments"]')->text());
        self::assertStringNotContainsString('Security', $crawler->filter('[data-board-rail="departments"]')->text());
    }

    public function testForeignDepartmentAnswersNotFoundRatherThanForbidden(): void
    {
        $mine = $this->department('Logistics');
        $theirs = $this->department('Security');

        $this->client->loginUser($this->user(['board:view'], $mine));
        $this->client->request('GET', '/board/'.$theirs->getUuid().'/'.date('Y-m-d'));

        self::assertResponseStatusCodeSame(404);
    }

    /** An unscoped grant is event-wide by design, so it must reach every department that runs shifts. */
    public function testUnscopedGrantReachesEveryDepartment(): void
    {
        $one = $this->department('Logistics');
        $two = $this->department('Security');

        $this->client->loginUser($this->user(['board:view'], null));
        $this->client->request('GET', '/board/'.$two->getUuid().'/'.date('Y-m-d'));

        self::assertResponseIsSuccessful();
    }

    /** An organizational department cannot own shifts, so a board for one would always be empty. */
    public function testOrganizationalDepartmentIsNotReachable(): void
    {
        $organizational = $this->department('Board', true);

        $this->client->loginUser($this->user(['board:view'], $organizational));
        $this->client->request('GET', '/board/'.$organizational->getUuid().'/'.date('Y-m-d'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testUserWithNoUsableDepartmentSeesTheEmptyState(): void
    {
        $organizational = $this->department('Board', true);

        $this->client->loginUser($this->user(['board:view'], $organizational));
        $this->client->request('GET', '/board');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-board-message]');
    }

    public function testEntryPointRedirectsToTheFirstReachableDepartment(): void
    {
        $mine = $this->department('Logistics');

        $this->client->loginUser($this->user(['board:view'], $mine));
        $this->client->request('GET', '/board');

        self::assertResponseRedirects('/board/'.$mine->getUuid());
    }

    public function testPrivilegeIsRequired(): void
    {
        $mine = $this->department('Logistics');

        $this->client->loginUser($this->user(['shift:manage'], $mine));
        $this->client->request('GET', '/board');

        self::assertResponseStatusCodeSame(403);
    }
}
