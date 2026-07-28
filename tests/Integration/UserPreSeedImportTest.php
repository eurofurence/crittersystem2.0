<?php

namespace App\Tests\Integration;

use App\Entity\Badge;
use App\Entity\Department;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Entity\SsoGroupMapping;
use App\Sso\SsoRoleSettings;
use App\Sso\UserPreSeedImporter;
use App\Tests\DatabaseTestCase;

/**
 * Protects the pre-seeder's security-critical guarantees: an entry whose id has no configured SSO
 * mapping or role grants nothing (and never creates a user on its own), the full mapping is applied
 * exactly as a real SSO login would apply it, position and admin come from the configured SSO role
 * ids (not the dump's "level"), and re-running never duplicates or overwrites identity fields.
 */
final class UserPreSeedImportTest extends DatabaseTestCase
{
    private function importer(): UserPreSeedImporter
    {
        return static::getContainer()->get(UserPreSeedImporter::class);
    }

    private function seedCatalogue(): void
    {
        // Positional and global-role groups the SSO appliers reconcile by slug.
        $this->em->persist(new Group('Department staff', 'department-staff'));
        $this->em->persist(new Group('Shift manager', 'shift-manager'));
        $this->em->persist(new Group('Global admin', 'global-admin', 'ROLE_ADMIN'));

        $department = new Department('Art Show', 'art-show');
        $infoDesk = new Group('Info Desk', 'info-desk');
        $badge = new Badge('Staff', 'staff');
        $type = new VolunteerType('Volunteer');
        $this->em->persist($department);
        $this->em->persist($infoDesk);
        $this->em->persist($badge);
        $this->em->persist($type);

        $mapping = (new SsoGroupMapping('GRP-DEPT'))->setName('Art Show')->setDepartment($department);
        $mapping->addPermissionGroup($infoDesk)->addBadge($badge)->addVolunteerType($type);
        $this->em->persist($mapping);
        $this->em->flush();

        // GRP-SHIFTMGR is the shift-manager role; GRP-GADMIN the global-admin role. Neither is a
        // group mapping, so on their own they place a user in no department.
        static::getContainer()->get(SsoRoleSettings::class)->save(null, 'GRP-SHIFTMGR', 'GRP-GADMIN', null);
    }

    /** @return array<int,array<string,mixed>> */
    private function dump(): array
    {
        return [
            ['id' => 'GRP-DEPT', 'type' => 'department', 'name' => 'Art Show', 'users' => [
                ['user_id' => 'SUB-A', 'username' => 'alice', 'level' => 'member'],
                ['user_id' => 'SUB-B', 'username' => 'bob', 'level' => 'owner'],
            ]],
            ['id' => 'GRP-SHIFTMGR', 'type' => 'team', 'name' => 'Shift Managers', 'users' => [
                ['user_id' => 'SUB-B', 'username' => 'bob', 'level' => 'member'],
            ]],
            ['id' => 'GRP-GADMIN', 'type' => 'team', 'name' => 'Global Admins', 'users' => [
                ['user_id' => 'SUB-C', 'username' => 'carol', 'level' => 'member'],
            ]],
            ['id' => 'GRP-UNMAPPED', 'type' => 'none', 'name' => 'Legacy Only', 'users' => [
                ['user_id' => 'SUB-D', 'username' => 'dave', 'level' => 'member'],
            ]],
        ];
    }

    private function user(string $sub): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['ssoUserId' => $sub]);
    }

    public function testUnmappedEntryNeitherCreatesUserNorGrants(): void
    {
        $this->seedCatalogue();

        $result = $this->importer()->import($this->dump());

        self::assertSame(1, $result['skippedUsers'], 'the user seen only in the unmapped entry is skipped');
        $this->em->clear();
        self::assertNull($this->user('SUB-D'), 'a user present only in an entry with no mapping/role is never created');
    }

    public function testAppliesTheFullMappingLikeAnSsoLogin(): void
    {
        $this->seedCatalogue();

        $result = $this->importer()->import($this->dump());
        self::assertSame(3, $result['created']);

        $this->em->clear();
        $alice = $this->user('SUB-A');
        self::assertNotNull($alice);
        self::assertSame(User::SOURCE_SSO, $alice->getAccountSource());
        self::assertSame('preseed+sub-a@pre-seed.invalid', $alice->getEmail(), 'created users get an undeliverable placeholder email');

        $slugsByDept = [];
        foreach ($alice->getGroupAssignments() as $assignment) {
            $slugsByDept[$assignment->getDepartment()?->getSlug() ?? '(global)'][] = $assignment->getGroup()->getSlug();
        }
        self::assertContains('department-staff', $slugsByDept['art-show'], 'default position is staff');
        self::assertContains('info-desk', $slugsByDept['art-show'], 'permission group is scoped to the department');

        $badgeSlugs = array_map(static fn (Badge $b): string => $b->getSlug(), $alice->getBadges()->toArray());
        self::assertContains('staff', $badgeSlugs);

        $membership = $this->em->getRepository(UserVolunteerType::class)->findOneBy(['user' => $alice]);
        self::assertNotNull($membership);
        self::assertTrue($membership->isConfirmed(), 'a mapped volunteer type is auto-confirmed');
    }

    public function testPositionAndAdminComeFromRolesNotLevel(): void
    {
        $this->seedCatalogue();
        $this->importer()->import($this->dump());
        $this->em->clear();

        // Bob is "owner" in the dump but that is ignored; he is shift manager because he is in the
        // group configured as the shift-manager role.
        $bob = $this->user('SUB-B');
        self::assertNotNull($bob);
        $bobArtShow = [];
        foreach ($bob->getGroupAssignments() as $assignment) {
            if ($assignment->getDepartment()?->getSlug() === 'art-show') {
                $bobArtShow[] = $assignment->getGroup()->getSlug();
            }
        }
        self::assertContains('shift-manager', $bobArtShow);
        self::assertNotContains('department-staff', $bobArtShow, 'the positional group is reconciled, not stacked');

        // Carol is only in the global-admin role group: created, made admin, placed in no department.
        $carol = $this->user('SUB-C');
        self::assertNotNull($carol);
        self::assertContains('ROLE_ADMIN', $carol->getRoles());
        foreach ($carol->getGroupAssignments() as $assignment) {
            self::assertNull($assignment->getDepartment(), 'a role-only user joins no department');
        }
    }

    public function testImportIsIdempotent(): void
    {
        $this->seedCatalogue();
        $this->importer()->import($this->dump());

        $second = $this->importer()->import($this->dump());
        self::assertSame(0, $second['created'], 'no user is recreated');
        self::assertSame(3, $second['updated']);

        $this->em->clear();
        $alice = $this->user('SUB-A');
        $artShow = array_filter(
            $alice->getGroupAssignments()->toArray(),
            static fn ($a): bool => $a->getDepartment()?->getSlug() === 'art-show',
        );
        // department-staff + info-desk, exactly once each.
        self::assertCount(2, $artShow, 're-running does not duplicate memberships');
    }

    public function testExistingUserBySubIsNotOverwritten(): void
    {
        $this->seedCatalogue();

        $existing = new User();
        $existing->setName('alice')->setEmail('alice@real.example')->setSsoUserId('SUB-A')
            ->setAccountSource(User::SOURCE_SSO)->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($existing);
        $this->em->flush();

        $result = $this->importer()->import($this->dump());
        self::assertSame(2, $result['created'], 'the pre-existing user is matched by sub, not recreated');

        $this->em->clear();
        $alice = $this->user('SUB-A');
        self::assertSame('alice@real.example', $alice->getEmail(), 'an existing user keeps their real email');
        $hasDepartment = false;
        foreach ($alice->getGroupAssignments() as $assignment) {
            if ($assignment->getDepartment()?->getSlug() === 'art-show') {
                $hasDepartment = true;
            }
        }
        self::assertTrue($hasDepartment, 'memberships are still applied to the existing user');
    }

    /**
     * Real mappings repeat a volunteer type across many groups (every department granting Staff).
     * A user in two such groups must still end up with one membership: the lookup behind the grant
     * is a database query and cannot see an insert pending in the same flush, so applying it per
     * mapping produced a duplicate INSERT that aborted the whole import.
     */
    public function testTwoGroupsGrantingTheSameVolunteerTypeCreateOneMembership(): void
    {
        $this->seedCatalogue();
        $type = $this->em->getRepository(VolunteerType::class)->findOneBy(['name' => 'Volunteer']);
        $second = (new SsoGroupMapping('GRP-DEPT2'))->setName('Dealers Den');
        $second->addVolunteerType($type);
        $this->em->persist($second);
        $this->em->flush();

        $result = $this->importer()->import([
            ['id' => 'GRP-DEPT', 'type' => 'department', 'name' => 'Art Show', 'users' => [
                ['user_id' => 'SUB-A', 'username' => 'alice', 'level' => 'member'],
            ]],
            ['id' => 'GRP-DEPT2', 'type' => 'department', 'name' => 'Dealers Den', 'users' => [
                ['user_id' => 'SUB-A', 'username' => 'alice', 'level' => 'member'],
            ]],
        ]);
        self::assertSame(1, $result['created']);

        $this->em->clear();
        $alice = $this->user('SUB-A');
        self::assertNotNull($alice);
        self::assertSame(1, $this->em->getRepository(UserVolunteerType::class)->count(['user' => $alice->getId()]));
    }

    public function testRejectsNonListPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->importer()->import(['not' => 'a list']);
    }
}
