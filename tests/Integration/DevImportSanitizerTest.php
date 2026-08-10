<?php

namespace App\Tests\Integration;

use App\Backup\DevImportSanitizer;
use App\Entity\PersonalData;
use App\Entity\User;
use App\Tests\DatabaseTestCase;
use Doctrine\DBAL\Connection;

/**
 * Protects the guarantee that a database imported from production cannot reach
 * the people in it, and that nothing it carries is unreadable here.
 *
 * Both halves have consequences beyond a failing page: a live Telegram link or
 * a queued message would be delivered for real, and a 2FA secret sealed with
 * production's key throws the moment its user is hydrated.
 */
final class DevImportSanitizerTest extends DatabaseTestCase
{
    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }

    private function importedUser(): int
    {
        $user = new User();
        $user->setName('imported')
            ->setEmail('imported@example.invalid')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword('irrelevant');
        $user->setPersonalData(new PersonalData($user));
        $this->em->persist($user);
        $this->em->flush();

        $id = (int) $user->getId();
        $this->em->clear();

        // Written past the ORM: the encrypted columns hold ciphertext this
        // instance's key cannot open, exactly as a production dump leaves them.
        $this->connection()->executeStatement(
            "UPDATE users SET telegram_id = '4242', telegram_handle = 'someone',
             telegram_acting_token = 'acting-token', telegram_linked_at = now(),
             totp_secret = 'sealed-elsewhere', backup_codes = 'sealed-elsewhere',
             two_factor_enabled = true WHERE id = ?",
            [$id],
        );

        return $id;
    }

    public function testItUnlinksTelegramAndClearsUnreadable2faSecrets(): void
    {
        $id = $this->importedUser();

        (new DevImportSanitizer($this->connection()))->sanitize();

        $row = $this->connection()->fetchAssociative(
            'SELECT telegram_id, telegram_acting_token, telegram_linked_at, totp_secret, backup_codes, two_factor_enabled
             FROM users WHERE id = ?',
            [$id],
        );

        self::assertIsArray($row);
        self::assertNull($row['telegram_id']);
        self::assertNull($row['telegram_acting_token']);
        self::assertNull($row['telegram_linked_at']);
        self::assertNull($row['totp_secret']);
        self::assertNull($row['backup_codes']);
        self::assertFalse(filter_var($row['two_factor_enabled'], \FILTER_VALIDATE_BOOL));
    }

    public function testTheUserIsLoadableAfterwards(): void
    {
        $id = $this->importedUser();

        (new DevImportSanitizer($this->connection()))->sanitize();
        $this->em->clear();

        self::assertInstanceOf(User::class, $this->em->find(User::class, $id));
    }

    public function testItEmptiesTheQueueAndRevokesOneTimeLinks(): void
    {
        $id = $this->importedUser();
        $connection = $this->connection();
        $connection->insert('messenger_messages', [
            'body' => 'O:8:"stdClass":0:{}',
            'headers' => '[]',
            'queue_name' => 'default',
            'created_at' => '2026-01-01 00:00:00',
            'available_at' => '2026-01-01 00:00:00',
        ]);
        $connection->executeStatement(
            "INSERT INTO password_resets (user_id, token, created_at) VALUES (?, 'reset-token', now())",
            [$id],
        );

        (new DevImportSanitizer($connection))->sanitize();

        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM messenger_messages'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM password_resets'));
    }

    public function testItClearsReferencesToFilesThatStayedInProduction(): void
    {
        $id = $this->importedUser();
        $connection = $this->connection();
        $connection->executeStatement(
            "UPDATE users_personal_data SET avatar_path = 'avatars/real.png' WHERE user_id = ?",
            [$id],
        );

        (new DevImportSanitizer($connection))->sanitize();

        self::assertNull($connection->fetchOne('SELECT avatar_path FROM users_personal_data WHERE user_id = ?', [$id]));
    }

    public function testItReportsWhatItDidWithoutFailingOnAbsentTables(): void
    {
        $notes = (new DevImportSanitizer($this->connection()))->sanitize();

        self::assertNotEmpty($notes);
        foreach ($notes as $note) {
            self::assertStringNotContainsString('skipped', $note);
        }
    }
}
