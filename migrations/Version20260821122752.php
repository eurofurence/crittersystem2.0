<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a user's last chosen shift filters are kept, so a screen they come back to looks the way
 * they left it.
 *
 * Nullable and empty for everybody: an account that has never set a filter behaves exactly as
 * before, and the first choice made is the first thing stored.
 */
final class Version20260821122752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember each user\'s shift filters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_settings ADD shift_filters JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users_settings DROP shift_filters');
    }
}
