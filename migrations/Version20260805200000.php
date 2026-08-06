<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marks the base volunteer types as global, so no department can claim or restrict them.
 *
 * DATA LOSS: any department that had claimed Staff or Volunteer loses that link, and "Department
 * only" is cleared on them. Both are exactly what a global type may not have. No membership,
 * assignment or shift data is touched, and the links cannot be restored by the down migration.
 */
final class Version20260805200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the global flag to volunteer types and set it on the base types.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE volunteer_types ADD is_global BOOLEAN DEFAULT FALSE NOT NULL');

        // The two types the installer seeds. Every department needs both to staff anything, so they
        // are the ones a stray claim hurts most.
        $this->addSql("UPDATE volunteer_types SET is_global = TRUE WHERE name IN ('Staff', 'Volunteer')");

        $this->addSql('DELETE FROM department_volunteer_types WHERE volunteer_type_id IN (SELECT id FROM volunteer_types WHERE is_global = TRUE)');
        $this->addSql('UPDATE volunteer_types SET department_only = FALSE WHERE is_global = TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE volunteer_types DROP is_global');
    }
}
