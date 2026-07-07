<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260627114917 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Two-factor authentication fields (TOTP secret, enabled, required, recovery codes)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD totp_secret TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD two_factor_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE users ADD two_factor_required BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE users ADD backup_codes TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users DROP totp_secret');
        $this->addSql('ALTER TABLE users DROP two_factor_enabled');
        $this->addSql('ALTER TABLE users DROP two_factor_required');
        $this->addSql('ALTER TABLE users DROP backup_codes');
    }
}
