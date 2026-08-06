<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remembers when a holder was warned that a certification is about to expire, so the nightly job
 * warns them once per validity period instead of every night until it runs out. Additive and
 * nullable: a record that has never been warned is exactly what null means.
 */
final class Version20260806090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track when a certification holder was warned about an approaching expiry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_certifications ADD expiry_reminded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_certifications DROP expiry_reminded_at');
    }
}
