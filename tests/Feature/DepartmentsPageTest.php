<?php

namespace App\Tests\Feature;

use App\Entity\Department;
use App\Entity\Group;
use App\Entity\PersonalData;
use App\Entity\Privilege;
use App\Entity\User;
use App\Entity\UserConsent;
use App\Entity\UserGroupAssignment;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DepartmentsPageTest extends DatabaseWebTestCase
{
    /**
     * A department member with a known email, optionally consenting to share it,
     * placed in the given department so they appear on its detail page.
     */
    private function memberOf(Department $department, string $name, bool $emailConsent, ?string $realName = null, bool $nameConsent = false): User
    {
        $memberGroup = new Group('Member-'.$name, 'member-'.$name, 'ROLE_STAFF');
        $this->em->persist($memberGroup);

        $member = new User();
        $member->setName($name)->setEmail($name.'-secret@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $member->setPassword('x');
        $member->completeOnboarding();
        $consent = (new UserConsent($member))->setEmailVisible($emailConsent)->setFullNameVisible($nameConsent);
        $member->setConsent($consent);
        if ($realName !== null) {
            $pd = new PersonalData($member);
            $pd->setFirstName($realName);
            $member->setPersonalData($pd);
            $this->em->persist($pd);
        }
        $this->em->persist($member);
        $this->em->persist($consent);
        $this->em->persist(new UserGroupAssignment($member, $memberGroup, $department));

        return $member;
    }

    /** A viewer holding department:manage scoped to the given department. */
    private function managerOf(Department $department): User
    {
        $group = new Group('Managers', 'mgr-grp', 'ROLE_STAFF');
        $priv = new Privilege('department:manage');
        $this->em->persist($priv);
        $group->addPrivilege($priv);
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $manager = new User();
        $manager->setName('mgr')->setEmail('mgr@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $manager->setPassword($hasher->hashPassword($manager, 'secret123'));
        $manager->completeOnboarding();
        $this->em->persist($manager);
        $this->em->persist($manager->assignGroup($group, $department));

        return $manager;
    }

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

    /** A member of a department gets its card, carrying the membership badge, and reaches its page. */
    public function testStaffSeesDepartmentCardsAndDashboard(): void
    {
        $user = $this->staffUser();
        $department = new Department('Stage', 'stage');
        $this->em->persist($department);
        $memberGroup = new Group('Member', 'member-grp', 'ROLE_STAFF');
        $this->em->persist($memberGroup);
        $this->em->persist(new UserGroupAssignment($user, $memberGroup, $department));
        $this->em->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/departments');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Stage');
        self::assertSelectorExists('.badge.text-bg-primary');

        $this->client->request('GET', '/departments/'.$department->getUuid());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Staffing');
    }

    /**
     * Consent alone does not open an address. A plain staff viewer is neither a manager of this
     * department nor Info Desk, so the email must appear nowhere on the page, including as the
     * href of a mailto: link in the row actions dropdown.
     */
    public function testPlainStaffNeverSeesMemberEmailEvenWithConsent(): void
    {
        $viewer = $this->staffUser();
        $department = new Department('Stage', 'stage');
        $this->em->persist($department);
        $this->memberOf($department, 'consenting', true);
        $this->em->flush();

        $this->client->loginUser($viewer);
        $this->client->request('GET', '/departments/'.$department->getUuid());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('consenting-secret@example.com', $this->client->getResponse()->getContent());
    }

    public function testManagerSeesMemberEmailOnlyWithConsent(): void
    {
        $department = new Department('Stage', 'stage');
        $this->em->persist($department);
        $this->memberOf($department, 'yes', true);
        $this->memberOf($department, 'no', false);
        $manager = $this->managerOf($department);
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->client->request('GET', '/departments/'.$department->getUuid());
        self::assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();
        self::assertStringContainsString('yes-secret@example.com', $body);
        self::assertStringNotContainsString('no-secret@example.com', $body);
    }

    public function testManagerSeesRealNameOnlyWithConsent(): void
    {
        $department = new Department('Stage', 'stage');
        $this->em->persist($department);
        $this->memberOf($department, 'shown', true, 'Ruestin', true);
        $this->memberOf($department, 'hidden', true, 'Secretiel', false);
        $manager = $this->managerOf($department);
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->client->request('GET', '/departments/'.$department->getUuid());
        self::assertResponseIsSuccessful();
        $body = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ruestin', $body);
        self::assertStringNotContainsString('Secretiel', $body);
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
