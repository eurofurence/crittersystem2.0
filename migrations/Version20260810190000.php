<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records why a goodie was handed over to somebody who was missing a certification it requires.
 *
 * Additive and nullable: every existing distribution reads as "not overridden", which is what they
 * all were, since nothing could be overridden before this.
 */
final class Version20260810190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the reason a goodie certification requirement was overridden.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goodie_distributions ADD certification_override_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goodie_distributions DROP certification_override_reason');
    }
}
