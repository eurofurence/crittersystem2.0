<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The flag that lets a write mark somebody's cached hours as needing recalculation.
 *
 * Every existing row is flagged on the way in. They were written under a cache that had no
 * invalidation and a day-long lifetime, so their totals cannot be trusted; flagging them makes the
 * first sweep rebuild them once, after which the flag is only ever set by an actual change.
 */
final class Version20260819121716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark cached hours as needing recalculation when the data behind them changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_hours_cache ADD dirty BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('UPDATE user_hours_cache SET dirty = true');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_hours_cache DROP dirty');
    }
}
