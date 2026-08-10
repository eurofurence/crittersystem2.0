<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a goodie item require certifications before it may be handed over, the way the legacy system
 * did: a first-aid pin is not given to somebody who never did the training.
 *
 * Only the join table is created. No item gains a requirement here, so every goodie keeps behaving
 * exactly as it does today until an administrator attaches a certification to one.
 */
final class Version20260810180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow goodie items to require certifications.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE goodie_item_certifications (
                goodie_item_id INT NOT NULL,
                certification_id INT NOT NULL,
                PRIMARY KEY (goodie_item_id, certification_id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_B2ED6C1593E0C29D ON goodie_item_certifications (goodie_item_id)');
        $this->addSql('CREATE INDEX IDX_B2ED6C15CB47068A ON goodie_item_certifications (certification_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE goodie_item_certifications
                ADD CONSTRAINT fk_goodie_item_certifications_item
                FOREIGN KEY (goodie_item_id) REFERENCES goodie_items (id)
                ON DELETE CASCADE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE goodie_item_certifications
                ADD CONSTRAINT fk_goodie_item_certifications_certification
                FOREIGN KEY (certification_id) REFERENCES certifications (id)
                ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE goodie_item_certifications');
    }
}
