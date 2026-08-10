<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grants the seeded staff groups the privilege for the page sign-in sends everyone to.
 *
 * Signing in without a target path lands on the news index, which requires `news:view`. Onboarding
 * grants the baseline Volunteer group only to non-staff, so a staff group without that privilege
 * leaves its members facing a 403 on a page they never chose - the first thing they see after
 * finishing onboarding.
 *
 * Scoped to the seeded groups by slug on purpose. A group an administrator built themselves is
 * their decision, and this must not quietly widen it; the catalog covers every group the installer
 * creates from here on.
 */
final class Version20260810220000 extends AbstractMigration
{
    private const GROUPS = [
        'shift-manager',
        'shift-manager-delegated',
        'department-manager',
        'department-staff',
        'certification-manager',
        'goodies-manager',
        'goodies-staff',
    ];

    public function getDescription(): string
    {
        return 'Grant news:view to the seeded staff groups so they can reach the page sign-in lands on.';
    }

    public function up(Schema $schema): void
    {
        $slugs = "'".implode("', '", self::GROUPS)."'";

        $this->addSql(<<<SQL
            INSERT INTO group_privileges (group_id, privilege_id)
            SELECT g.id, p.id
            FROM groups g
            CROSS JOIN privileges p
            WHERE g.slug IN ($slugs)
              AND p.name = 'news:view'
              AND NOT EXISTS (
                  SELECT 1 FROM group_privileges gp
                  WHERE gp.group_id = g.id AND gp.privilege_id = p.id
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $slugs = "'".implode("', '", self::GROUPS)."'";

        $this->addSql(<<<SQL
            DELETE FROM group_privileges
            WHERE privilege_id = (SELECT id FROM privileges WHERE name = 'news:view')
              AND group_id IN (SELECT id FROM groups WHERE slug IN ($slugs))
            SQL);
    }
}
