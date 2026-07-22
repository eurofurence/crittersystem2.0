<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the bot @username for building Telegram deep links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_configuration ADD bot_username VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_configuration DROP bot_username');
    }
}
