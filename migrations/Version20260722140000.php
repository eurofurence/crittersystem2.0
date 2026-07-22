<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-link Telegram acting token so unlinking revokes the bot credential';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD telegram_acting_token VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_telegram_acting_token ON users (telegram_acting_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_users_telegram_acting_token');
        $this->addSql('ALTER TABLE users DROP telegram_acting_token');
    }
}
