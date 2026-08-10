<?php

declare(strict_types=1);

namespace App\Backup;

use Doctrine\DBAL\Connection;

/**
 * Makes a freshly imported production database safe to run against locally.
 *
 * Two separate reasons, both mandatory:
 *
 *  - Reachability. A development copy holds live Telegram links, queued
 *    messages and valid one-time links for real people. Left in place, the
 *    local worker would deliver them.
 *  - Decryptability. Columns of type `encrypted_string` are sealed with
 *    APP_ENCRYPTION_KEY, and development deliberately uses a different key.
 *    Reading such a value throws, so a single 2FA-enrolled user would make
 *    every page that hydrates them fail. They are cleared, not translated:
 *    clearing 2FA secrets locally is wanted, the production ones are not.
 *
 * Rows referencing uploaded files are cleared as well - the files live in
 * production storage, so their paths resolve to nothing here.
 */
final class DevImportSanitizer
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<string> what was done, for the command's summary
     */
    public function sanitize(): array
    {
        $existing = $this->connection->createSchemaManager()->listTableNames();
        $notes = [];

        foreach ($this->steps() as $step) {
            $missing = array_diff($step['tables'], $existing);
            if ($missing !== []) {
                $notes[] = sprintf('skipped (no %s table): %s', implode(', ', $missing), $step['note']);
                continue;
            }

            // TRUNCATE reports no affected rows, so those steps say up front how much they will remove.
            $affected = isset($step['count']) ? (int) $this->connection->fetchOne($step['count']) : 0;
            foreach ($step['sql'] as $sql) {
                $executed = $this->connection->executeStatement($sql);
                $affected += isset($step['count']) ? 0 : $executed;
            }
            $notes[] = sprintf('%s (%d row(s))', $step['note'], $affected);
        }

        return $notes;
    }

    /**
     * @return list<array{tables: list<string>, sql: list<string>, note: string, count?: string}>
     */
    private function steps(): array
    {
        return [
            [
                'tables' => ['users'],
                'sql' => ['UPDATE users SET totp_secret = NULL, backup_codes = NULL, two_factor_enabled = false
                           WHERE totp_secret IS NOT NULL OR backup_codes IS NOT NULL OR two_factor_enabled'],
                'note' => 'cleared 2FA secrets (sealed with the production key)',
            ],
            [
                'tables' => ['users'],
                'sql' => ['UPDATE users SET telegram_id = NULL, telegram_handle = NULL,
                           telegram_acting_token = NULL, telegram_linked_at = NULL
                           WHERE telegram_id IS NOT NULL OR telegram_acting_token IS NOT NULL'],
                'note' => 'unlinked every Telegram account',
            ],
            [
                'tables' => ['telegram_configuration'],
                'sql' => ['UPDATE telegram_configuration SET enabled = false, api_key = NULL'],
                'note' => 'disabled the Telegram bot connection',
            ],
            [
                'tables' => ['signing_certificates'],
                'sql' => ['DELETE FROM signing_certificates'],
                'note' => 'dropped digital-ID signing certificates (sealed with the production key)',
            ],
            [
                'tables' => ['messenger_messages'],
                'sql' => ['TRUNCATE messenger_messages RESTART IDENTITY'],
                'count' => 'SELECT count(*) FROM messenger_messages',
                'note' => 'emptied the message queue',
            ],
            [
                'tables' => ['telegram_link_requests', 'password_resets', 'invite_tokens', 'digital_id_tokens', 'certification_tokens'],
                'sql' => ['TRUNCATE telegram_link_requests, password_resets, invite_tokens, digital_id_tokens, certification_tokens RESTART IDENTITY'],
                'count' => 'SELECT (SELECT count(*) FROM telegram_link_requests) + (SELECT count(*) FROM password_resets)
                            + (SELECT count(*) FROM invite_tokens) + (SELECT count(*) FROM digital_id_tokens)
                            + (SELECT count(*) FROM certification_tokens)',
                'note' => 'revoked outstanding one-time links and tokens',
            ],
            [
                'tables' => ['login_attempts', 'login_lockouts'],
                'sql' => ['TRUNCATE login_attempts, login_lockouts RESTART IDENTITY'],
                'count' => 'SELECT (SELECT count(*) FROM login_attempts) + (SELECT count(*) FROM login_lockouts)',
                'note' => 'cleared login attempts and lockouts',
            ],
            [
                'tables' => ['data_exports', 'audit_exports'],
                'sql' => ['DELETE FROM data_exports', 'DELETE FROM audit_exports'],
                'note' => 'removed export records whose files are not here',
            ],
            [
                'tables' => ['users_personal_data'],
                'sql' => ['UPDATE users_personal_data SET avatar_path = NULL WHERE avatar_path IS NOT NULL'],
                'note' => 'cleared avatar file references',
            ],
            [
                'tables' => ['chat_messages'],
                'sql' => ['UPDATE chat_messages SET image_path = NULL WHERE image_path IS NOT NULL'],
                'note' => 'cleared chat image references',
            ],
        ];
    }
}
