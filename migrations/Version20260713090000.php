<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grant volunteertype:view and location:view to the Volunteer group.
 *
 * The navigation gates its "Volunteer Types" and "Locations" entries on these
 * privileges, but the Volunteer baseline never held them, so a volunteer had no
 * way to reach the page where volunteer types are joined. New installs get this
 * from PrivilegeCatalog::VOLUNTEER; existing databases need this migration,
 * because app:migrate does not re-run the seeder.
 */
final class Version20260713090000 extends AbstractMigration
{
    private const PRIVILEGES = [
        'volunteertype:view' => 'View volunteer types',
        'location:view' => 'View locations',
    ];

    public function getDescription(): string
    {
        return 'Add volunteertype:view and location:view to the Volunteer group.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::PRIVILEGES as $name => $description) {
            // The privilege usually exists already (other groups hold it), but a
            // database seeded before it was catalogued may be missing the row.
            $this->addSql(
                'INSERT INTO privileges (name, description) SELECT :name, :description
                 WHERE NOT EXISTS (SELECT 1 FROM privileges WHERE name = :name)',
                ['name' => $name, 'description' => $description],
            );

            $this->addSql(
                'INSERT INTO group_privileges (group_id, privilege_id)
                 SELECT g.id, p.id FROM groups g, privileges p
                 WHERE g.slug = \'volunteer\' AND p.name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM group_privileges gp WHERE gp.group_id = g.id AND gp.privilege_id = p.id
                   )',
                ['name' => $name],
            );
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::PRIVILEGES) as $name) {
            $this->addSql(
                'DELETE FROM group_privileges
                 WHERE group_id = (SELECT id FROM groups WHERE slug = \'volunteer\')
                   AND privilege_id = (SELECT id FROM privileges WHERE name = :name)',
                ['name' => $name],
            );
        }
    }
}
