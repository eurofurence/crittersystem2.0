<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711224909 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Location embed_html';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE locations ADD embed_html TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE locations DROP embed_html');
    }
}
