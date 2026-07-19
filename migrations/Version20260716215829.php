<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716215829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-shift check-in/check-out timestamps on shift entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift_entries ADD checked_in_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE shift_entries ADD checked_out_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift_entries DROP checked_in_at');
        $this->addSql('ALTER TABLE shift_entries DROP checked_out_at');
    }
}
