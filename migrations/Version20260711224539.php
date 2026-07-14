<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711224539 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Location parent hierarchy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE locations ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE locations ADD CONSTRAINT FK_17E64ABA727ACA70 FOREIGN KEY (parent_id) REFERENCES locations (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_17E64ABA727ACA70 ON locations (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE locations DROP CONSTRAINT FK_17E64ABA727ACA70');
        $this->addSql('DROP INDEX IDX_17E64ABA727ACA70');
        $this->addSql('ALTER TABLE locations DROP parent_id');
    }
}
