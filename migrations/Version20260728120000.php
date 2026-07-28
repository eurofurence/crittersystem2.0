<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rank volunteer types in pickers, with the base types first';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE volunteer_types ADD sort_order INT DEFAULT 100 NOT NULL');
        // Backfill the two seeded base types so existing installs get the same order a fresh one
        // gets from the installer. A renamed base type keeps the default and simply sorts with the
        // rest; an admin can set it explicitly.
        $this->addSql("UPDATE volunteer_types SET sort_order = 10 WHERE name = 'Staff'");
        $this->addSql("UPDATE volunteer_types SET sort_order = 20 WHERE name = 'Volunteer'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE volunteer_types DROP sort_order');
    }
}
