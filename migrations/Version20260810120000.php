<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives every staff group access to the staff shift-apply screen.
 *
 * Staff are pointed at /manage-shifts/apply from the shift list, but the screen requires
 * `manageshifts:view`, which several staff groups never carried - a staff member in one of them
 * followed the link and was refused. Grants are added, never removed, and only where the pairing is
 * missing, so re-running changes nothing and no other privilege is touched.
 *
 * Only `ROLE_STAFF` groups are touched: an admin group satisfies every check through `global:admin`
 * and a sub-admin group is seeded with every sub-admin-level privilege, so neither is missing this.
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grant manageshifts:view to every group carrying a staff role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
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

    /**
     * Deliberately empty. Which groups held the privilege beforehand is not recorded anywhere, so
     * revoking it would take it from groups that always had it and lock real staff out of a screen
     * they use during the event.
     */
    public function down(Schema $schema): void
    {
    }
}
