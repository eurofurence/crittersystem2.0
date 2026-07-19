<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260717113903 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Queue an onboarding re-run per user, applied at their next sign-in';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD onboarding_reset_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP onboarding_reset_requested_at');
    }
}
