<?php

namespace App\Tests\Integration;

use App\Entity\Group;
use App\Entity\Privilege;
use App\Security\PrivilegeCatalog;
use App\Tests\DatabaseTestCase;
use Doctrine\DBAL\Connection;

/**
 * Every staff group reaches the staff shift-apply screen.
 *
 * The shift list points staff at /manage-shifts/apply, which enforces `manageshifts:view`. Several
 * staff groups never carried it, so a staff member in one of them followed the link and was refused.
 * Both halves are covered: the seeded presets a fresh install builds from, and the migration that
 * repairs an existing database.
 */
final class StaffManageShiftsGrantTest extends DatabaseTestCase
{
    private const PRIVILEGE = 'manageshifts:view';

    /**
     * Slugs of seeded ROLE_STAFF groups whose preset lacks the privilege. Only plain staff are
     * considered: an admin group is satisfied by global:admin, and the sub-admin group's
     * '*subadmin*' wildcard is expanded by the seeder to include this privilege.
     *
     * @return string[]
     */
    private function staffPresetsMissingTheGrant(): array
    {
        $missing = [];
        foreach (PrivilegeCatalog::GROUPS as $slug => $definition) {
            if (($definition['role'] ?? null) !== 'ROLE_STAFF' || !\is_array($definition['permissions'])) {
                continue;
            }
            if (!\in_array(self::PRIVILEGE, $definition['permissions'], true)) {
                $missing[] = $slug;
            }
        }

        return $missing;
    }

    public function testEverySeededStaffGroupCarriesTheGrant(): void
    {
        self::assertSame(
            [],
            $this->staffPresetsMissingTheGrant(),
            'a group with a staff role that cannot open the staff shift screen is offered a link it will be refused',
        );
    }

    /**
     * The migration adds the pairing wherever it is absent and touches nothing else, so an existing
     * production database is repaired without revoking anything.
     */
    public function testTheMigrationGrantsItToAStaffGroupThatLacksIt(): void
    {
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => self::PRIVILEGE])
            ?? new Privilege(self::PRIVILEGE);
        $this->em->persist($privilege);

        $other = new Privilege('goodie:view-'.bin2hex(random_bytes(3)));
        $this->em->persist($other);

        $staff = new Group('Goodies Staff '.bin2hex(random_bytes(3)), 'gs-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        $staff->addPrivilege($other);
        $this->em->persist($staff);

        $volunteer = new Group('Volunteers '.bin2hex(random_bytes(3)), 'vol-'.bin2hex(random_bytes(3)), null);
        $this->em->persist($volunteer);
        $this->em->flush();

        $this->runGrant();

        self::assertTrue($this->holds($staff, $privilege), 'the staff group must gain the grant');
        self::assertTrue($this->holds($staff, $other), 'no other privilege may be disturbed');
        self::assertFalse($this->holds($volunteer, $privilege), 'a group without a staff role must not be granted anything');
    }

    public function testRunningItTwiceChangesNothing(): void
    {
        $privilege = $this->em->getRepository(Privilege::class)->findOneBy(['name' => self::PRIVILEGE])
            ?? new Privilege(self::PRIVILEGE);
        $this->em->persist($privilege);

        $staff = new Group('Staff '.bin2hex(random_bytes(3)), 'st-'.bin2hex(random_bytes(3)), 'ROLE_STAFF');
        $this->em->persist($staff);
        $this->em->flush();

        $this->runGrant();
        $this->runGrant();

        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM group_privileges WHERE group_id = ? AND privilege_id = ?',
            [$staff->getId(), $privilege->getId()],
        ));
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }

    /** The migration's statement, run against the test database. */
    private function runGrant(): void
    {
        $this->connection()->executeStatement(<<<'SQL'
            INSERT INTO group_privileges (group_id, privilege_id)
            SELECT g.id, p.id
            FROM groups g
            CROSS JOIN privileges p
            WHERE p.name = 'manageshifts:view'
              AND g.role = 'ROLE_STAFF'
              AND NOT EXISTS (
                  SELECT 1 FROM group_privileges gp
                  WHERE gp.group_id = g.id AND gp.privilege_id = p.id
              )
            SQL);
    }

    private function holds(Group $group, Privilege $privilege): bool
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM group_privileges WHERE group_id = ? AND privilege_id = ?',
            [$group->getId(), $privilege->getId()],
        ) === 1;
    }
}
