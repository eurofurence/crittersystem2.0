<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename Shift Type to Shift Task. Data-preserving rename of the table,
 * columns, indexes and constraints (no drop/recreate).
 */
final class Version20260711222111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename shift_types -> shift_tasks (data-preserving)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift_types RENAME TO shift_tasks');
        $this->addSql('ALTER INDEX uniq_52de6f6e5e237e06 RENAME TO UNIQ_5BB683C95E237E06');
        $this->addSql('ALTER INDEX idx_52de6f6eae80f5df RENAME TO IDX_5BB683C9AE80F5DF');
        $this->addSql('ALTER TABLE shift_tasks RENAME CONSTRAINT fk_52de6f6eae80f5df TO FK_5BB683C9AE80F5DF');

        $this->addSql('ALTER TABLE shifts RENAME COLUMN shift_type_id TO shift_task_id');
        $this->addSql('ALTER INDEX idx_1d1d712fa81db0ea RENAME TO IDX_1D1D712FE0E73DFF');
        $this->addSql('ALTER TABLE shifts RENAME CONSTRAINT fk_1d1d712fa81db0ea TO FK_1D1D712FE0E73DFF');

        $this->addSql('ALTER TABLE needed_volunteer_types RENAME COLUMN shift_type_id TO shift_task_id');
        $this->addSql('ALTER INDEX idx_b0c805b9a81db0ea RENAME TO IDX_B0C805B9E0E73DFF');
        $this->addSql('ALTER TABLE needed_volunteer_types RENAME CONSTRAINT fk_b0c805b9a81db0ea TO FK_B0C805B9E0E73DFF');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE needed_volunteer_types RENAME CONSTRAINT fk_b0c805b9e0e73dff TO FK_B0C805B9A81DB0EA');
        $this->addSql('ALTER INDEX idx_b0c805b9e0e73dff RENAME TO IDX_B0C805B9A81DB0EA');
        $this->addSql('ALTER TABLE needed_volunteer_types RENAME COLUMN shift_task_id TO shift_type_id');

        $this->addSql('ALTER TABLE shifts RENAME CONSTRAINT fk_1d1d712fe0e73dff TO FK_1D1D712FA81DB0EA');
        $this->addSql('ALTER INDEX idx_1d1d712fe0e73dff RENAME TO IDX_1D1D712FA81DB0EA');
        $this->addSql('ALTER TABLE shifts RENAME COLUMN shift_task_id TO shift_type_id');

        $this->addSql('ALTER TABLE shift_tasks RENAME CONSTRAINT fk_5bb683c9ae80f5df TO FK_52DE6F6EAE80F5DF');
        $this->addSql('ALTER INDEX idx_5bb683c9ae80f5df RENAME TO IDX_52DE6F6EAE80F5DF');
        $this->addSql('ALTER INDEX uniq_5bb683c95e237e06 RENAME TO UNIQ_52DE6F6E5E237E06');
        $this->addSql('ALTER TABLE shift_tasks RENAME TO shift_types');
    }
}
