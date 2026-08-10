<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pins the two base volunteer types to a role, and repairs the users onboarding left without one.
 *
 * Onboarding used to find the type to assign by its English name. That name is editable, so renaming
 * Volunteer to Critter made the lookup miss and the assignment was skipped without a word: the user
 * finished onboarding, was told it worked, and ended up with no volunteer type and therefore no way
 * to be rostered.
 *
 * The backfill below cannot rely on the name either, for exactly that reason. It falls back to the
 * shape the installer gives the base types - global, and staff-only or not - and to the oldest such
 * type, which is the seeded one on any installation where an administrator has added more.
 */
final class Version20260810210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Give the base volunteer types a stable role and assign the default type to users missing it.';
    }

    /**
     * The role backfill matches the shipped names first, then falls back to the shape the installer
     * gives the base types - global, and staff-only or not - taking the oldest match, because an
     * installation that renamed them is exactly the one this fixes.
     *
     * The membership backfill covers everyone who finished onboarding with no type at all, each
     * given the role onboarding would have given them; staff is decided by the roles their groups
     * carry, which is what User::isStaff() reads. `confirmed_by` is the user themselves, as
     * onboarding does it, because the system grants this - left null it would show up as a pending
     * request for somebody to approve.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE volunteer_types ADD role VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B5E54C257698A6A ON volunteer_types (role)');

        $this->addSql("UPDATE volunteer_types SET role = 'volunteer' WHERE name = 'Volunteer'");
        $this->addSql("UPDATE volunteer_types SET role = 'staff' WHERE name = 'Staff'");

        $this->addSql(<<<'SQL'
            UPDATE volunteer_types SET role = 'staff'
            WHERE id = (
                SELECT id FROM volunteer_types
                WHERE role IS NULL AND is_global = TRUE AND staff_only = TRUE
                ORDER BY id LIMIT 1
            )
            AND NOT EXISTS (SELECT 1 FROM volunteer_types WHERE role = 'staff')
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE volunteer_types SET role = 'volunteer'
            WHERE id = (
                SELECT id FROM volunteer_types
                WHERE role IS NULL AND is_global = TRUE AND staff_only = FALSE
                ORDER BY id LIMIT 1
            )
            AND NOT EXISTS (SELECT 1 FROM volunteer_types WHERE role = 'volunteer')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO user_volunteer_types (uuid, user_id, volunteer_type_id, confirmed_by, supporter, created_at)
            SELECT gen_random_uuid(), u.id, vt.id, u.id, FALSE, NOW()
            FROM users u
            JOIN volunteer_types vt ON vt.role = CASE
                WHEN EXISTS (
                    SELECT 1 FROM user_group_assignments uga
                    JOIN groups g ON g.id = uga.group_id
                    WHERE uga.user_id = u.id AND g.role IN ('ROLE_STAFF', 'ROLE_SUBADMIN', 'ROLE_ADMIN')
                ) THEN 'staff' ELSE 'volunteer' END
            WHERE u.onboarding_completed = TRUE
              AND NOT EXISTS (SELECT 1 FROM user_volunteer_types t WHERE t.user_id = u.id)
            SQL);
    }

    /**
     * The memberships added by up() are deliberately kept: they are indistinguishable from ones
     * granted normally, and removing every membership of the base types would take real ones with
     * them.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_6B5E54C257698A6A');
        $this->addSql('ALTER TABLE volunteer_types DROP role');
    }
}
