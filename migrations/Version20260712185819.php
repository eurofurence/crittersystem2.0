<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a random public UUID identifier to every entity that appears in a URL, so internal
 * auto-increment primary keys are no longer exposed (enumeration / IDOR hardening). The column is
 * added nullable, existing rows are backfilled with gen_random_uuid(), then NOT NULL + a unique
 * index are enforced.
 */
final class Version20260712185819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public UUID identifiers to URL-exposed entities (stop leaking internal DB ids).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment_proposals ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE assignment_proposals SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE assignment_proposals ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_68A93E68D17F50A6 ON assignment_proposals (uuid)');
        $this->addSql('ALTER TABLE badges ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE badges SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE badges ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_78F6539AD17F50A6 ON badges (uuid)');
        $this->addSql('ALTER TABLE banned_identities ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE banned_identities SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE banned_identities ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DB69FE1BD17F50A6 ON banned_identities (uuid)');
        $this->addSql('ALTER TABLE chat_messages ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE chat_messages SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE chat_messages ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EF20C9A6D17F50A6 ON chat_messages (uuid)');
        $this->addSql('ALTER TABLE consent_texts ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE consent_texts SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE consent_texts ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_172A5C14D17F50A6 ON consent_texts (uuid)');
        $this->addSql('ALTER TABLE conversations ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE conversations SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE conversations ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C2521BF1D17F50A6 ON conversations (uuid)');
        $this->addSql('ALTER TABLE delegated_manager_requests ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE delegated_manager_requests SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE delegated_manager_requests ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6833FE11D17F50A6 ON delegated_manager_requests (uuid)');
        $this->addSql('ALTER TABLE faqs ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE faqs SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE faqs ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8934BEE5D17F50A6 ON faqs (uuid)');
        $this->addSql('ALTER TABLE groups ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE groups SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE groups ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F06D3970D17F50A6 ON groups (uuid)');
        $this->addSql('ALTER TABLE help_calls ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE help_calls SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE help_calls ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BD3B9C76D17F50A6 ON help_calls (uuid)');
        $this->addSql('ALTER TABLE locations ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE locations SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE locations ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_17E64ABAD17F50A6 ON locations (uuid)');
        $this->addSql('ALTER TABLE named_positions ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE named_positions SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE named_positions ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8E658BBD17F50A6 ON named_positions (uuid)');
        $this->addSql('ALTER TABLE needed_volunteer_types ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE needed_volunteer_types SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE needed_volunteer_types ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B0C805B9D17F50A6 ON needed_volunteer_types (uuid)');
        $this->addSql('ALTER TABLE news ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE news SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE news ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1DD39950D17F50A6 ON news (uuid)');
        $this->addSql('ALTER TABLE notifications ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE notifications SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE notifications ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6000B0D3D17F50A6 ON notifications (uuid)');
        $this->addSql('ALTER TABLE questions ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE questions SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE questions ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8ADC54D5D17F50A6 ON questions (uuid)');
        $this->addSql('ALTER TABLE request_links ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE request_links SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE request_links ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8C951D3DD17F50A6 ON request_links (uuid)');
        $this->addSql('ALTER TABLE shift_entries ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE shift_entries SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE shift_entries ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_40A98FBCD17F50A6 ON shift_entries (uuid)');
        $this->addSql('ALTER TABLE shift_position_assignments ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE shift_position_assignments SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE shift_position_assignments ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6C77B21CD17F50A6 ON shift_position_assignments (uuid)');
        $this->addSql('ALTER TABLE shift_positions ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE shift_positions SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE shift_positions ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_70D3615D17F50A6 ON shift_positions (uuid)');
        $this->addSql('ALTER TABLE shift_tasks ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE shift_tasks SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE shift_tasks ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5BB683C9D17F50A6 ON shift_tasks (uuid)');
        $this->addSql('ALTER TABLE shifts ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE shifts SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE shifts ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1D1D712FD17F50A6 ON shifts (uuid)');
        $this->addSql('ALTER TABLE sso_group_mappings ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE sso_group_mappings SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE sso_group_mappings ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D0C0A823D17F50A6 ON sso_group_mappings (uuid)');
        $this->addSql('ALTER TABLE user_volunteer_types ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE user_volunteer_types SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE user_volunteer_types ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7982384DD17F50A6 ON user_volunteer_types (uuid)');
        $this->addSql('ALTER TABLE users ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE users SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE users ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9D17F50A6 ON users (uuid)');
        $this->addSql('ALTER TABLE volunteer_types ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE volunteer_types SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE volunteer_types ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B5E54C2D17F50A6 ON volunteer_types (uuid)');
        $this->addSql('ALTER TABLE worklogs ADD uuid UUID DEFAULT NULL');
        $this->addSql('UPDATE worklogs SET uuid = gen_random_uuid()');
        $this->addSql('ALTER TABLE worklogs ALTER COLUMN uuid SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C78A883AD17F50A6 ON worklogs (uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_68A93E68D17F50A6');
        $this->addSql('ALTER TABLE assignment_proposals DROP uuid');
        $this->addSql('DROP INDEX UNIQ_78F6539AD17F50A6');
        $this->addSql('ALTER TABLE badges DROP uuid');
        $this->addSql('DROP INDEX UNIQ_DB69FE1BD17F50A6');
        $this->addSql('ALTER TABLE banned_identities DROP uuid');
        $this->addSql('DROP INDEX UNIQ_EF20C9A6D17F50A6');
        $this->addSql('ALTER TABLE chat_messages DROP uuid');
        $this->addSql('DROP INDEX UNIQ_172A5C14D17F50A6');
        $this->addSql('ALTER TABLE consent_texts DROP uuid');
        $this->addSql('DROP INDEX UNIQ_C2521BF1D17F50A6');
        $this->addSql('ALTER TABLE conversations DROP uuid');
        $this->addSql('DROP INDEX UNIQ_6833FE11D17F50A6');
        $this->addSql('ALTER TABLE delegated_manager_requests DROP uuid');
        $this->addSql('DROP INDEX UNIQ_8934BEE5D17F50A6');
        $this->addSql('ALTER TABLE faqs DROP uuid');
        $this->addSql('DROP INDEX UNIQ_F06D3970D17F50A6');
        $this->addSql('ALTER TABLE groups DROP uuid');
        $this->addSql('DROP INDEX UNIQ_BD3B9C76D17F50A6');
        $this->addSql('ALTER TABLE help_calls DROP uuid');
        $this->addSql('DROP INDEX UNIQ_17E64ABAD17F50A6');
        $this->addSql('ALTER TABLE locations DROP uuid');
        $this->addSql('DROP INDEX UNIQ_8E658BBD17F50A6');
        $this->addSql('ALTER TABLE named_positions DROP uuid');
        $this->addSql('DROP INDEX UNIQ_B0C805B9D17F50A6');
        $this->addSql('ALTER TABLE needed_volunteer_types DROP uuid');
        $this->addSql('DROP INDEX UNIQ_1DD39950D17F50A6');
        $this->addSql('ALTER TABLE news DROP uuid');
        $this->addSql('DROP INDEX UNIQ_6000B0D3D17F50A6');
        $this->addSql('ALTER TABLE notifications DROP uuid');
        $this->addSql('DROP INDEX UNIQ_8ADC54D5D17F50A6');
        $this->addSql('ALTER TABLE questions DROP uuid');
        $this->addSql('DROP INDEX UNIQ_8C951D3DD17F50A6');
        $this->addSql('ALTER TABLE request_links DROP uuid');
        $this->addSql('DROP INDEX UNIQ_40A98FBCD17F50A6');
        $this->addSql('ALTER TABLE shift_entries DROP uuid');
        $this->addSql('DROP INDEX UNIQ_6C77B21CD17F50A6');
        $this->addSql('ALTER TABLE shift_position_assignments DROP uuid');
        $this->addSql('DROP INDEX UNIQ_70D3615D17F50A6');
        $this->addSql('ALTER TABLE shift_positions DROP uuid');
        $this->addSql('DROP INDEX UNIQ_5BB683C9D17F50A6');
        $this->addSql('ALTER TABLE shift_tasks DROP uuid');
        $this->addSql('DROP INDEX UNIQ_1D1D712FD17F50A6');
        $this->addSql('ALTER TABLE shifts DROP uuid');
        $this->addSql('DROP INDEX UNIQ_D0C0A823D17F50A6');
        $this->addSql('ALTER TABLE sso_group_mappings DROP uuid');
        $this->addSql('DROP INDEX UNIQ_7982384DD17F50A6');
        $this->addSql('ALTER TABLE user_volunteer_types DROP uuid');
        $this->addSql('DROP INDEX UNIQ_1483A5E9D17F50A6');
        $this->addSql('ALTER TABLE users DROP uuid');
        $this->addSql('DROP INDEX UNIQ_6B5E54C2D17F50A6');
        $this->addSql('ALTER TABLE volunteer_types DROP uuid');
        $this->addSql('DROP INDEX UNIQ_C78A883AD17F50A6');
        $this->addSql('ALTER TABLE worklogs DROP uuid');
    }
}
