<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Telegram visibility consent and provenance (timestamp + notice version) to user_consents';
    }

    public function up(Schema $schema): void
    {
        // Add NOT NULL with a default to backfill existing rows, then drop the
        // default so the column matches the mapping (which carries no DB default).
        $this->addSql('ALTER TABLE user_consents ADD telegram_visible BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE user_consents ALTER telegram_visible DROP DEFAULT');
        $this->addSql('ALTER TABLE user_consents ADD visibility_consented_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_consents ADD visibility_notice_version VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_consents DROP telegram_visible');
        $this->addSql('ALTER TABLE user_consents DROP visibility_consented_at');
        $this->addSql('ALTER TABLE user_consents DROP visibility_notice_version');
    }
}
