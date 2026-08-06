<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records why and when a certification record was last decided, so a manager seeing a repeat
 * application knows it was already turned down once and what for. Additive: both columns are
 * nullable and nothing is backfilled, since no decision before this point recorded a reason.
 */
final class Version20260805220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the reason and date of the last decision on a user certification.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_certifications ADD decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_certifications ADD decision_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_certifications DROP decided_at');
        $this->addSql('ALTER TABLE user_certifications DROP decision_reason');
    }
}
