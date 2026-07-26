<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track the SSO picture URL an avatar was downloaded from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_personal_data ADD avatar_source VARCHAR(1024) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_personal_data DROP avatar_source');
    }
}
