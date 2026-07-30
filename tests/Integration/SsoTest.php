<?php

namespace App\Tests\Integration;

use App\Entity\Badge;
use App\Entity\BannedIdentity;
use App\Entity\Department;
use App\Entity\Group;
use App\Entity\Privilege;
use App\Entity\SsoGroupMapping;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Gdpr\BanChecker;
use App\Sso\BannedIdentityException;
use App\Sso\SsoClaims;
use App\Sso\SsoMappingImporter;
use App\Sso\SsoUserProvisioner;
use App\Tests\DatabaseTestCase;

final class SsoTest extends DatabaseTestCase
{
    private function seedTargets(): void
    {
        $this->em->persist(new Department('Art Show', 'art-show'));
        $this->em->persist(new Group('Info Desk', 'info-desk', 'ROLE_STAFF'));
        $this->em->persist(new Badge('Security', 'security', Badge::TYPE_STANDARD));
        $this->em->persist(new VolunteerType('Volunteer'));
        $this->em->flush();
    }

    public function testImportCreatesMappingAndResolvesSlugs(): void
    {
        $this->seedTargets();
        /** @var SsoMappingImporter $importer */
        $importer = static::getContainer()->get(SsoMappingImporter::class);

        $result = $importer->import([[
            'id' => '0RV39Y2PM21J4N6L',
            'name' => 'Art Show',
            'slug' => 'art-show',
            'staffonly' => 1,
            'department' => 'art-show',
            'badges' => ['security'],
            'volunteertype' => ['Volunteer'],
            'permissiongroup' => ['info-desk'],
        ], [
            'id' => 'BADROW',
            'permissiongroup' => ['does-not-exist'],
        ]]);

        self::assertSame(2, $result['imported']);
        self::assertNotEmpty($result['warnings']);

        $this->em->clear();
        $mapping = $this->em->getRepository(SsoGroupMapping::class)->findOneBy(['ssoGroupId' => '0RV39Y2PM21J4N6L']);
        self::assertNotNull($mapping);
        self::assertTrue($mapping->isStaffOnly());
        self::assertSame('art-show', $mapping->getDepartment()?->getSlug());
        self::assertCount(1, $mapping->getPermissionGroups());
        self::assertCount(1, $mapping->getBadges());
        self::assertCount(1, $mapping->getVolunteerTypes());
    }

    public function testExportRoundTripsThroughImport(): void
    {
        $this->seedTargets();
        /** @var SsoMappingImporter $importer */
        $importer = static::getContainer()->get(SsoMappingImporter::class);
        $importer->import([[
            'id' => '0RV39Y2PM21J4N6L',
            'name' => 'Art Show',
            'slug' => 'art-show',
            'staffonly' => 1,
            'department' => 'art-show',
            'badges' => ['security'],
            'volunteertype' => ['Volunteer'],
            'permissiongroup' => ['info-desk'],
        ]]);

        $rows = $importer->export();
        self::assertCount(1, $rows);
        self::assertSame('0RV39Y2PM21J4N6L', $rows[0]['id']);
        self::assertSame('art-show', $rows[0]['department']);
        self::assertSame(['info-desk'], $rows[0]['permissiongroup']);
        self::assertSame(['Volunteer'], $rows[0]['volunteertype']);
        self::assertSame(['security'], $rows[0]['badges']);

        // Re-importing the exported rows updates in place rather than duplicating.
        $result = $importer->import($rows);
        self::assertSame(1, $result['imported']);
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(SsoGroupMapping::class)->findAll());
    }

    public function testExportOfAnEmptyDatabaseReturnsATemplateRow(): void
    {
        /** @var SsoMappingImporter $importer */
        $importer = static::getContainer()->get(SsoMappingImporter::class);

        $rows = $importer->export();
        self::assertCount(1, $rows);
        self::assertArrayHasKey('id', $rows[0]);
        self::assertArrayHasKey('permissiongroup', $rows[0]);
    }

    public function testImportCreatesMissingDepartmentFromSlug(): void
    {
        /** @var SsoMappingImporter $importer */
        $importer = static::getContainer()->get(SsoMappingImporter::class);

        // Two rows point at the same not-yet-existing department slug.
        $result = $importer->import([
            ['id' => 'GRP-A', 'name' => 'Art Show Team', 'slug' => 'art-show-team', 'department' => 'art-show'],
            ['id' => 'GRP-B', 'name' => 'Art Show Crew', 'slug' => 'art-show-crew', 'department' => 'art-show'],
        ]);

        self::assertSame(2, $result['imported']);
        $this->em->clear();

        // Exactly one department was created, named after the first mapping.
        $departments = $this->em->getRepository(Department::class)->findAll();
        self::assertCount(1, $departments);
        self::assertSame('art-show', $departments[0]->getSlug());
        self::assertSame('Art Show Team', $departments[0]->getName());

        // Both mappings link to that same department.
        $repo = $this->em->getRepository(SsoGroupMapping::class);
        self::assertSame('art-show', $repo->findOneBy(['ssoGroupId' => 'GRP-A'])->getDepartment()?->getSlug());
        self::assertSame('art-show', $repo->findOneBy(['ssoGroupId' => 'GRP-B'])->getDepartment()?->getSlug());
    }

    public function testImportLinksExistingDepartmentWithoutCreatingDuplicate(): void
    {
        $this->em->persist(new Department('Security', 'security'));
        $this->em->flush();

        /** @var SsoMappingImporter $importer */
        $importer = static::getContainer()->get(SsoMappingImporter::class);
        $importer->import([['id' => 'GRP-S', 'name' => 'Security', 'slug' => 'sec', 'department' => 'security']]);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Department::class)->findAll(), 'existing department reused');
        self::assertSame(
            'security',
            $this->em->getRepository(SsoGroupMapping::class)->findOneBy(['ssoGroupId' => 'GRP-S'])->getDepartment()?->getSlug(),
        );
    }

    public function testProvisionCreatesLockedSsoUserWithMappings(): void
    {
        $this->seedTargets();
        static::getContainer()->get(SsoMappingImporter::class)->import([[
            'id' => 'GRP1', 'name' => 'Art Show', 'slug' => 'art-show', 'department' => 'art-show',
            'badges' => ['security'], 'volunteertype' => ['Volunteer'], 'permissiongroup' => ['info-desk'],
        ]]);

        /** @var SsoUserProvisioner $provisioner */
        $provisioner = static::getContainer()->get(SsoUserProvisioner::class);
        $claims = new SsoClaims('sub-123', 'alice@example.com', 'alice', 'Alice Wonder', ['GRP1']);
        $user = $provisioner->provision($claims);

        self::assertTrue($user->isSsoManaged());
        self::assertSame('sub-123', $user->getSsoUserId());
        self::assertFalse($user->canEditFullName());
        self::assertSame('Alice', $user->getPersonalData()?->getFirstName());
        self::assertContains('ROLE_STAFF', $user->getRoles());
        $badgeSlugs = array_map(static fn (Badge $b) => $b->getSlug(), $user->getBadges()->toArray());
        self::assertContains('security', $badgeSlugs);

        // The mapping is authoritative: no supporter has to confirm the membership.
        $membership = $this->em->getRepository(UserVolunteerType::class)->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($membership);
        self::assertTrue($membership->isConfirmed(), 'an SSO-mapped volunteer type is confirmed on provisioning');

        // Idempotent: a second login does not duplicate the volunteer type.
        $provisioner->provision($claims);
        $this->em->clear();
        $count = $this->em->getRepository(UserVolunteerType::class)->count(['user' => $user->getId()]);
        self::assertSame(1, $count);
    }

    public function testProvisionConfirmsAnAlreadyPendingVolunteerType(): void
    {
        $this->seedTargets();
        static::getContainer()->get(SsoMappingImporter::class)->import([[
            'id' => 'GRP1', 'name' => 'Art Show', 'slug' => 'art-show', 'volunteertype' => ['Volunteer'],
        ]]);

        /** @var SsoUserProvisioner $provisioner */
        $provisioner = static::getContainer()->get(SsoUserProvisioner::class);

        // First login without the mapped group: the user self-requests the type and it stays pending.
        $claims = new SsoClaims('sub-9', 'bob@example.com', 'bob', 'Bob Builder', []);
        $user = $provisioner->provision($claims);
        $type = $this->em->getRepository(VolunteerType::class)->findOneBy(['name' => 'Volunteer']);
        $pending = new UserVolunteerType($user, $type);
        $this->em->persist($pending);
        $this->em->flush();
        self::assertFalse($pending->isConfirmed());

        // The provider now reports the group; the pending membership is confirmed rather than duplicated.
        $provisioner->provision(new SsoClaims('sub-9', 'bob@example.com', 'bob', 'Bob Builder', ['GRP1']));

        $this->em->clear();
        $memberships = $this->em->getRepository(UserVolunteerType::class)->findBy(['user' => $user->getId()]);
        self::assertCount(1, $memberships);
        self::assertTrue($memberships[0]->isConfirmed());
    }

    /**
     * Several SSO groups granting the same volunteer type must still produce one membership.
     * The membership lookup is a database query, so it cannot see an insert already pending in
     * the same flush - applying the grant per mapping made the second one a duplicate INSERT
     * that the (user, volunteer_type) unique index rejected, failing the whole login.
     */
    public function testSeveralMappingsGrantingTheSameVolunteerTypeCreateOneMembership(): void
    {
        $this->seedTargets();
        static::getContainer()->get(SsoMappingImporter::class)->import([
            ['id' => 'GRP1', 'name' => 'Art Show', 'slug' => 'art-show', 'volunteertype' => ['Volunteer']],
            ['id' => 'GRP2', 'name' => 'Info Desk', 'slug' => 'info-desk-team', 'volunteertype' => ['Volunteer']],
        ]);

        /** @var SsoUserProvisioner $provisioner */
        $provisioner = static::getContainer()->get(SsoUserProvisioner::class);
        $user = $provisioner->provision(new SsoClaims('sub-dup', 'dup@example.com', 'dup', 'Dup User', ['GRP1', 'GRP2']));

        $this->em->clear();
        $memberships = $this->em->getRepository(UserVolunteerType::class)->findBy(['user' => $user->getId()]);
        self::assertCount(1, $memberships);
        self::assertTrue($memberships[0]->isConfirmed());
    }

    /** The provider may report the same group id twice; that must not grant its type twice. */
    public function testARepeatedGroupIdInTheClaimsCreatesOneMembership(): void
    {
        $this->seedTargets();
        static::getContainer()->get(SsoMappingImporter::class)->import([
            ['id' => 'GRP1', 'name' => 'Art Show', 'slug' => 'art-show', 'volunteertype' => ['Volunteer']],
        ]);

        /** @var SsoUserProvisioner $provisioner */
        $provisioner = static::getContainer()->get(SsoUserProvisioner::class);
        $user = $provisioner->provision(new SsoClaims('sub-rep', 'rep@example.com', 'rep', 'Rep User', ['GRP1', 'GRP1']));

        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(UserVolunteerType::class)->count(['user' => $user->getId()]));
    }

    /**
     * A department mapping alone must leave the user able to use the app. The positional group a
     * department confers carries ROLE_STAFF but not the baseline privileges, so an SSO user mapped
     * only into a department held no news:view and was denied the page every sign-in lands on.
     */
    public function testProvisionGrantsTheBaselinePermissionGroupToDepartmentStaff(): void
    {
        $this->em->persist(new Department('Art Show', 'art-show'));
        $this->em->persist(new Group('Department staff', 'department-staff', 'ROLE_STAFF'));
        $baseline = new Group('Volunteer', 'volunteer');
        $newsView = new Privilege('news:view');
        $this->em->persist($newsView);
        $baseline->addPrivilege($newsView);
        $this->em->persist($baseline);
        $this->em->flush();

        static::getContainer()->get(SsoMappingImporter::class)->import([[
            'id' => 'GRP-DEPT', 'name' => 'Art Show', 'slug' => 'art-show', 'department' => 'art-show',
        ]]);

        /** @var SsoUserProvisioner $provisioner */
        $provisioner = static::getContainer()->get(SsoUserProvisioner::class);
        $user = $provisioner->provision(new SsoClaims('sub-dept', 'dept@example.com', 'deptuser', 'Dept User', ['GRP-DEPT']));

        $slugs = array_map(static fn (Group $g): string => $g->getSlug(), $user->getGroups()->toArray());
        self::assertContains('department-staff', $slugs);
        self::assertContains('volunteer', $slugs);
        self::assertTrue($user->hasPrivilege('news:view'));

        // Idempotent: a second sign-in must not add a duplicate unscoped assignment.
        $provisioner->provision(new SsoClaims('sub-dept', 'dept@example.com', 'deptuser', 'Dept User', ['GRP-DEPT']));
        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($user->getId());
        $baselineCount = 0;
        foreach ($reloaded->getGroupAssignments() as $assignment) {
            if ($assignment->getGroup()->getSlug() === 'volunteer') {
                ++$baselineCount;
            }
        }
        self::assertSame(1, $baselineCount);
    }

    public function testBannedIdentityIsRefused(): void
    {
        /** @var BanChecker $bans */
        $bans = static::getContainer()->get(BanChecker::class);
        $this->em->persist(new BannedIdentity(BannedIdentity::TYPE_SSO, $bans->hashSso('banned-sub')));
        $this->em->flush();

        $provisioner = static::getContainer()->get(SsoUserProvisioner::class);
        $this->expectException(BannedIdentityException::class);
        $provisioner->provision(new SsoClaims('banned-sub', 'b@example.com', 'b', 'B', []));
    }
}
