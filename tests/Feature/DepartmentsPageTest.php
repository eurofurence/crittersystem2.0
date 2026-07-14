<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\UserGroupAssignment;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DepartmentsPageTest extends DatabaseWebTestCase
{
    private function staffUser(): User
    {
        $group = new Group('Staff', 'staff-grp', 'ROLE_STAFF');
        $this->em->persist($group);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName('staffer')->setEmail('staffer@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testStaffSeesDepartmentCardsAndDashboard(): void
    {
        $user = $this->staffUser();
        $department = new Department('Stage', 'stage');
        $this->em->persist($department);
        // Scope the user into the department.
        $memberGroup = new Group('Member', 'member-grp', 'ROLE_STAFF');
        $this->em->persist($memberGroup);
        $this->em->persist(new UserGroupAssignment($user, $memberGroup, $department));
        $this->em->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/departments');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Stage');
        self::assertSelectorExists('.badge.bg-primary'); // Member badge

        $this->client->request('GET', '/departments/'.$department->getUuid());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Staffing');
    }

    public function testOrganizationalHiddenFromNonAdmin(): void
    {
        $user = $this->staffUser();
        $org = (new Department('Org', 'org'))->setOrganizational(true);
        $this->em->persist($org);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/departments/'.$org->getUuid());
        self::assertResponseStatusCodeSame(403);
    }
}
