<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives position groups the public UUID every URL-exposed entity carries, so the matrix planner's
 * position form stops posting the internal primary key. Added nullable, backfilled with
 * gen_random_uuid(), then NOT NULL plus a unique index.
 */
final class Version20260805120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a public UUID to position groups.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE position_groups ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE position_groups SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE position_groups ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88357FDD17F50A6 ON position_groups (uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_88357FDD17F50A6');
        $this->addSql('ALTER TABLE position_groups DROP uuid');
    }
}
