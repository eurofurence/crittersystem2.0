<?php

namespace App\Tests\Feature;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\User;
use App\Security\PrivilegeCatalog;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NavbarTest extends DatabaseWebTestCase
{
    /**
     * @param string[] $privileges
     */
    private function login(string $slug, array $privileges, ?string $role = null): void
    {
        $group = new Group(ucfirst($slug), $slug, $role);
        foreach ($privileges as $name) {
            $privilege = new Privilege($name);
            $this->em->persist($privilege);
            $group->addPrivilege($privilege);
        }
        $this->em->persist($group);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setName($slug.'user')->setEmail($slug.'@example.com')->setApiKey(bin2hex(random_bytes(16)));
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->addGroup($group);
        $user->completeOnboarding();
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    /** @return string[] */
    private function navTitles(): array
    {
        $crawler = $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        return $crawler->filter('#navbar-menu .nav-link-title')->each(fn ($node) => trim($node->text()));
    }

    /**
     * The volunteer is granted exactly what the seeded Volunteer group grants, so this test moves
     * with the catalog instead of drifting from it. Dashboard and Development (the navigation and
     * theme kits) are admin-only and must stay out of the menu.
     */
    public function testVolunteerSeesPublicEntriesOnly(): void
    {
        $this->login('volunteer', PrivilegeCatalog::VOLUNTEER);
        $titles = $this->navTitles();

        foreach (['News', 'My Shifts', 'Shifts', 'Critter Types', 'Locations', 'Bounty Board', 'Ask Info Desk', 'Certifications', 'FAQ'] as $expected) {
            self::assertContains($expected, $titles, "Volunteer should see {$expected}");
        }
        foreach (['Home', 'Shift Manager', 'Departments', 'Backstage', 'Manage', 'Audit', 'Messages', 'Dashboard', 'Development'] as $absent) {
            self::assertNotContains($absent, $titles, "Volunteer should not see {$absent}");
        }
    }

    /**
     * The Shift Manager entry is gated on the module privilege, not on the staff role. Staff are
     * offered "Messages" instead of "Ask Info Desk".
     */
    public function testStaffSeesShiftManagerAndDepartments(): void
    {
        $this->login('staff', ['message:use', 'shift:view', 'manageshifts:view'], 'ROLE_STAFF');
        $titles = $this->navTitles();

        self::assertContains('Shift Manager', $titles);
        self::assertContains('Departments', $titles);
        self::assertContains('Messages', $titles);
        self::assertNotContains('Ask Info Desk', $titles);
    }

    /**
     * Audit is not a top-level entry: it lives under Manage, and /admin/audit is not a route.
     * The viewer holds audit:view as a bare privilege rather than ROLE_ADMIN, which would force a
     * 2FA-enrolment redirect before the menu could be read.
     */
    public function testAuditViewerSeesManageAndAuditMovedUnderManage(): void
    {
        $this->login('auditor', ['audit:view']);
        $titles = $this->navTitles();

        self::assertContains('Manage', $titles);
        self::assertNotContains('Audit', $titles);

        $this->client->request('GET', '/admin/audit');
        self::assertResponseStatusCodeSame(404);
    }
}
