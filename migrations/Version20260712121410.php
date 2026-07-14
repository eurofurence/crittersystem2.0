<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712121410 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conversation participant typing indicator.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation_participants ADD typing_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation_participants DROP typing_at');
    }
}
