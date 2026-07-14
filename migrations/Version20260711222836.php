<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711222836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Department organizational flag + required shifts.department_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE departments ADD organizational BOOLEAN DEFAULT false NOT NULL');

        // Add the column nullable, backfill, then enforce NOT NULL.
        $this->addSql('ALTER TABLE shifts ADD department_id INT DEFAULT NULL');
        $this->addSql('UPDATE shifts SET department_id = st.department_id FROM shift_tasks st WHERE st.id = shifts.shift_task_id AND shifts.department_id IS NULL');
        $this->addSql("INSERT INTO departments (uuid, name, slug, staff_only, organizational) SELECT gen_random_uuid(), 'General', 'general', false, false WHERE NOT EXISTS (SELECT 1 FROM departments WHERE slug = 'general')");
        $this->addSql("UPDATE shifts SET department_id = (SELECT id FROM departments WHERE slug = 'general') WHERE department_id IS NULL");
        $this->addSql('ALTER TABLE shifts ALTER COLUMN department_id SET NOT NULL');

        $this->addSql('ALTER TABLE shifts ADD CONSTRAINT FK_1D1D712FAE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1D1D712FAE80F5DF ON shifts (department_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE departments DROP organizational');
        $this->addSql('ALTER TABLE shifts DROP CONSTRAINT FK_1D1D712FAE80F5DF');
        $this->addSql('DROP INDEX IDX_1D1D712FAE80F5DF');
        $this->addSql('ALTER TABLE shifts DROP department_id');
    }
}
