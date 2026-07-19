<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711220003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-show bans - ban reason/automatic/user link, no-show baseline';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE banned_identities ADD reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE banned_identities ADD is_automatic BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE banned_identities ADD no_show_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE banned_identities ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE banned_identities ADD CONSTRAINT FK_DB69FE1BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_DB69FE1BA76ED395 ON banned_identities (user_id)');
        $this->addSql('ALTER TABLE users ADD no_show_baseline_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE banned_identities DROP CONSTRAINT FK_DB69FE1BA76ED395');
        $this->addSql('DROP INDEX IDX_DB69FE1BA76ED395');
        $this->addSql('ALTER TABLE banned_identities DROP reason');
        $this->addSql('ALTER TABLE banned_identities DROP is_automatic');
        $this->addSql('ALTER TABLE banned_identities DROP no_show_count');
        $this->addSql('ALTER TABLE banned_identities DROP user_id');
        $this->addSql('ALTER TABLE users DROP no_show_baseline_at');
    }
}
