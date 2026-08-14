<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `board:view` privilege and grants it to the seeded groups that already manage or staff
 * a department's shifts.
 *
 * The catalog covers every group a fresh install creates, so this exists only for databases that were
 * seeded before the privilege was defined. Groups an administrator built themselves are left alone
 * on purpose: widening them is their decision, not a migration's.
 */
final class Version20260813090000 extends AbstractMigration
{
    private const GROUPS = [
        'shift-manager',
        'shift-manager-delegated',
        'department-manager',
        'info-desk',
    ];

    public function getDescription(): string
    {
        return 'Add the board:view privilege and grant it to the seeded shift-management groups.';
    }

    public function up(Schema $schema): void
    {
        $slugs = "'".implode("', '", self::GROUPS)."'";

        $this->addSql(<<<SQL
            INSERT INTO privileges (name, description)
            SELECT 'board:view', 'Open the live operations board for a department'
            WHERE NOT EXISTS (SELECT 1 FROM privileges WHERE name = 'board:view')
            SQL);

        $this->addSql(<<<SQL
            INSERT INTO group_privileges (group_id, privilege_id)
            SELECT g.id, p.id
            FROM groups g
            CROSS JOIN privileges p
            WHERE g.slug IN ($slugs)
              AND p.name = 'board:view'
              AND NOT EXISTS (
                  SELECT 1 FROM group_privileges gp
                  WHERE gp.group_id = g.id AND gp.privilege_id = p.id
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<SQL
            DELETE FROM group_privileges
            WHERE privilege_id = (SELECT id FROM privileges WHERE name = 'board:view')
            SQL);

        $this->addSql("DELETE FROM privileges WHERE name = 'board:view'");
    }
}
