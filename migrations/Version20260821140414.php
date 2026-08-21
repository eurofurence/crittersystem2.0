<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets the info desk undo a goodie it handed out or recorded in error, without deleting the record.
 *
 * A revoked row keeps its history and gains the actor, the moment and an optional reason; every
 * count of what a volunteer has received ignores it, which makes the item claimable again. A
 * corrected row keeps the quantity it was created with in original_quantity, so a hand-out that was
 * merely mistyped stays distinguishable from one that was withdrawn.
 *
 * The public UUID is added nullable and backfilled before it is made NOT NULL, because these rows
 * exist in every deployment already and the column is what now addresses them in a URL.
 */
final class Version20260821140414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow goodie handovers to be revoked and their quantity corrected';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goodie_distributions ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE goodie_distributions SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE goodie_distributions ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_92A2A85AD17F50A6 ON goodie_distributions (uuid)');

        $this->addSql('ALTER TABLE goodie_distributions ADD revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE goodie_distributions ADD revoked_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE goodie_distributions ADD revoke_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE goodie_distributions ADD original_quantity INT DEFAULT NULL');
        $this->addSql('ALTER TABLE goodie_distributions ADD corrected_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE goodie_distributions ADD corrected_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE goodie_distributions ADD correction_reason TEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE goodie_distributions ADD CONSTRAINT FK_92A2A85A8E5493E3 FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE goodie_distributions ADD CONSTRAINT FK_92A2A85A855E22B6 FOREIGN KEY (corrected_by) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_92A2A85A8E5493E3 ON goodie_distributions (revoked_by)');
        $this->addSql('CREATE INDEX IDX_92A2A85A855E22B6 ON goodie_distributions (corrected_by)');
    }

    /**
     * Reverting discards which handovers were revoked and what they originally said, so anything
     * the desk corrected reappears as if it had been given.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goodie_distributions DROP CONSTRAINT FK_92A2A85A8E5493E3');
        $this->addSql('ALTER TABLE goodie_distributions DROP CONSTRAINT FK_92A2A85A855E22B6');
        $this->addSql('DROP INDEX IDX_92A2A85A8E5493E3');
        $this->addSql('DROP INDEX IDX_92A2A85A855E22B6');
        $this->addSql('DROP INDEX UNIQ_92A2A85AD17F50A6');
        $this->addSql('ALTER TABLE goodie_distributions DROP revoked_at');
        $this->addSql('ALTER TABLE goodie_distributions DROP revoked_by');
        $this->addSql('ALTER TABLE goodie_distributions DROP revoke_reason');
        $this->addSql('ALTER TABLE goodie_distributions DROP original_quantity');
        $this->addSql('ALTER TABLE goodie_distributions DROP corrected_at');
        $this->addSql('ALTER TABLE goodie_distributions DROP corrected_by');
        $this->addSql('ALTER TABLE goodie_distributions DROP correction_reason');
        $this->addSql('ALTER TABLE goodie_distributions DROP uuid');
    }
}
