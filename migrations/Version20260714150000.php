<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shift task names become unique per department instead of globally.
 *
 * A globally unique name means the first department to create "Briefing" prevents every other
 * department from having one, which makes department-specific tasks unusable.
 *
 * Two indexes replace the single global one:
 *   - (department_id, name) unique - a department cannot repeat a name. PostgreSQL treats NULLs as
 *     distinct, so this does NOT constrain the global tasks;
 *   - a partial unique index on name WHERE department_id IS NULL - the global pool keeps unique
 *     names of its own. Doctrine cannot express a partial index, so it is created here directly.
 *
 * Non-destructive: the new constraints are strictly weaker than the old one, so existing rows
 * (whose names are globally unique today) all satisfy them.
 */
final class Version20260714150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shift task names unique per department, not globally';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_5bb683c95e237e06');
        $this->addSql('CREATE UNIQUE INDEX uniq_shift_task_department_name ON shift_tasks (department_id, name)');
        $this->addSql('CREATE UNIQUE INDEX uniq_shift_task_global_name ON shift_tasks (name) WHERE department_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_shift_task_global_name');
        $this->addSql('DROP INDEX uniq_shift_task_department_name');
        $this->addSql('CREATE UNIQUE INDEX uniq_5bb683c95e237e06 ON shift_tasks (name)');
    }
}
