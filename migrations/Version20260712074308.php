<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712074308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shift audience mode, publication state, check-in override and optimistic-lock version; shift entry application/assignment state.';
    }

    public function up(Schema $schema): void
    {
        // Existing shifts are already live and public - backfill accordingly, then
        // drop the column default so the ORM (which sets the value) owns it.
        $this->addSql("ALTER TABLE shift_entries ADD state VARCHAR(16) NOT NULL DEFAULT 'assignment'");
        $this->addSql('ALTER TABLE shift_entries ALTER state DROP DEFAULT');

        $this->addSql("ALTER TABLE shifts ADD audience VARCHAR(32) NOT NULL DEFAULT 'public_volunteer'");
        $this->addSql("ALTER TABLE shifts ADD state VARCHAR(16) NOT NULL DEFAULT 'published'");
        $this->addSql('ALTER TABLE shifts ADD require_checkin BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE shifts ADD version INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE shifts ALTER audience DROP DEFAULT');
        $this->addSql('ALTER TABLE shifts ALTER state DROP DEFAULT');
        $this->addSql('ALTER TABLE shifts ALTER require_checkin DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift_entries DROP state');
        $this->addSql('ALTER TABLE shifts DROP audience');
        $this->addSql('ALTER TABLE shifts DROP state');
        $this->addSql('ALTER TABLE shifts DROP require_checkin');
        $this->addSql('ALTER TABLE shifts DROP version');
    }
}
