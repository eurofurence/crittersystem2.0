<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712103149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manual-assignment override marking on shift entries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift_entries ADD overridden BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE shift_entries ALTER overridden DROP DEFAULT');
        $this->addSql('ALTER TABLE shift_entries ADD override_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift_entries DROP overridden');
        $this->addSql('ALTER TABLE shift_entries DROP override_reason');
    }
}
