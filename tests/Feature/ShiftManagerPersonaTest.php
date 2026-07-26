<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Staff Shift Manager routing and personas: the module is gated by
 * manageshifts:view, ordinary staff see only application/overview actions, and
 * managers additionally see the planning actions.
 */
final class ShiftManagerPersonaTest extends DatabaseWebTestCase
{
    /** @param string[] $privileges */
    private function makeUser(string $name, array $privileges): User
    {
        $group = new Group('Group '.$name, 'group-'.$name, 'ROLE_STAFF');
        foreach ($privileges as $privilegeName) {
            $privilege = new Privilege($privilegeName);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($name)->setEmail($name.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/manage-shifts');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testUserWithoutModulePrivilegeIsForbidden(): void
    {
        $this->client->loginUser($this->makeUser('plain', ['shift:view']));
        $this->client->request('GET', '/manage-shifts');
        self::assertResponseStatusCodeSame(403);
    }

    public function testOrdinaryStaffSeesApplicationButNotManagementActions(): void
    {
        $this->client->loginUser($this->makeUser('staff', ['manageshifts:view', 'shift:self']));
        $crawler = $this->client->request('GET', '/manage-shifts');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-action="apply"]')->count());
        self::assertSame(1, $crawler->filter('[data-action="duty"]')->count(), 'staff must be able to reach the start-duty page');
        self::assertSame(0, $crawler->filter('[data-action="manage"]')->count(), 'ordinary staff must not see the manage action');
        self::assertSame(0, $crawler->filter('[data-action="create"]')->count());
    }

    public function testManagerSeesManagementActions(): void
    {
        $this->client->loginUser($this->makeUser('mgr', ['manageshifts:view', 'shift:manage', 'shift:self']));
        $crawler = $this->client->request('GET', '/manage-shifts');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-action="apply"]')->count());
        self::assertSame(1, $crawler->filter('[data-action="manage"]')->count());
        self::assertSame(1, $crawler->filter('[data-action="grid"]')->count(), 'a manager reaches the department grid');
    }

    public function testOrdinaryStaffCannotOpenPlanner(): void
    {
        $this->client->loginUser($this->makeUser('staff2', ['manageshifts:view']));
        $this->client->request('GET', '/manage-shifts/planner');
        self::assertResponseStatusCodeSame(403);
    }

    public function testApplyPageLoadsForStaff(): void
    {
        $this->client->loginUser($this->makeUser('staff3', ['manageshifts:view']));
        $this->client->request('GET', '/manage-shifts/apply');
        self::assertResponseIsSuccessful();
    }
}
