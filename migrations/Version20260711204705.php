<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711204705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profile avatar path';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_personal_data ADD avatar_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_personal_data DROP avatar_path');
    }
}
