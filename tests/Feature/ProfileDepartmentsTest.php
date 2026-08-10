<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\UserGroupAssignment;
use App\Tests\DatabaseWebTestCase;
use App\Tests\Support\ShiftScenario;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The profile names the departments a user belongs to, next to their volunteer types.
 *
 * Membership is an active department-scoped group assignment - the same definition the shift
 * visibility rules use - so an unscoped assignment, which makes someone a member of nothing, must
 * not appear here as membership of anything.
 */
final class ProfileDepartmentsTest extends DatabaseWebTestCase
{
    private ShiftScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = new ShiftScenario($this->em, static::getContainer()->get(UserPasswordHasherInterface::class));
    }

    private function department(string $name): Department
    {
        $department = new Department($name, strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(3)));
        $this->em->persist($department);

        return $department;
    }

    private function assign(User $user, ?Department $department, ?\DateTimeImmutable $expiresAt = null): void
    {
        $group = new Group('Grp '.bin2hex(random_bytes(3)), 'grp-'.bin2hex(random_bytes(3)), 'ROLE_USER');
        $this->em->persist($group);

        $assignment = new UserGroupAssignment($user, $group, $department);
        if ($expiresAt !== null) {
            $assignment->setExpiresAt($expiresAt);
        }
        $this->em->persist($assignment);
        $this->em->flush();
    }

    public function testOwnProfileNamesEveryDepartment(): void
    {
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->assign($user, $this->department('Stage Crew'));
        $this->assign($user, $this->department('Registration'));

        $this->client->loginUser($user);
        $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Stage Crew', $body);
        self::assertStringContainsString('Registration', $body);
    }

    public function testAUserWithNoDepartmentGetsTheEmptyState(): void
    {
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->client->loginUser($user);

        $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No departments.', (string) $this->client->getResponse()->getContent());
    }

    /**
     * An assignment with no department is event-wide, not membership of every department; listing
     * one here would tell a volunteer they belong to departments they have never been near.
     */
    public function testAnUnscopedAssignmentIsNotADepartment(): void
    {
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->department('Stage Crew');
        $this->assign($user, null);

        $this->client->loginUser($user);
        $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Stage Crew', (string) $this->client->getResponse()->getContent());
    }

    public function testAnExpiredAssignmentIsNotADepartment(): void
    {
        $user = $this->scenario->user(memberOf: $this->scenario->type);
        $this->assign($user, $this->department('Stage Crew'), new \DateTimeImmutable('-1 day'));

        $this->client->loginUser($user);
        $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Stage Crew', (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherUsersProfileNamesTheirDepartments(): void
    {
        $subject = $this->scenario->user(memberOf: $this->scenario->type);
        $this->assign($subject, $this->department('Stage Crew'));

        $viewer = $this->scenario->user(['shift:view', 'shift:self', 'profile:view'], $this->scenario->type);
        $this->client->loginUser($viewer);
        $this->client->request('GET', '/users/'.$subject->getUuid());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Stage Crew', (string) $this->client->getResponse()->getContent());
    }
}
