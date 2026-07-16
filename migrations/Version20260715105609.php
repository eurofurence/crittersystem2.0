<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add the unique `alias` key to locations. Existing rows are backfilled from a slug of the name;
 * collisions and empty slugs are disambiguated with the row id so the unique index holds.
 */
final class Version20260715105609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a unique alias to locations, backfilling existing rows from the name.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE locations ADD alias VARCHAR(64) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE locations AS l SET alias = CASE
                WHEN base.slug IS NULL THEN 'location-' || l.id
                WHEN base.rn > 1        THEN base.slug || '-' || l.id
                ELSE base.slug
            END
            FROM (
                SELECT id, slug, row_number() OVER (PARTITION BY slug ORDER BY id) AS rn
                FROM (
                    SELECT id, NULLIF(trim(both '-' from lower(regexp_replace(name, '[^a-z0-9]+', '-', 'gi'))), '') AS slug
                    FROM locations
                ) AS slugged
            ) AS base
            WHERE l.id = base.id
            SQL);

        $this->addSql('ALTER TABLE locations ALTER COLUMN alias SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_17E64ABAE16C6B94 ON locations (alias)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_17E64ABAE16C6B94');
        $this->addSql('ALTER TABLE locations DROP alias');
    }
}
