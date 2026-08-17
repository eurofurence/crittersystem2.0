<?php

declare(strict_types=1);

namespace App\Backup;

use App\Entity\Contact;
use App\Entity\PersonalData;
use App\Entity\Settings;
use App\Entity\State;
use App\Entity\User;
use App\Repository\GroupRepository;
use App\Service\EventConfigStore;
use App\Service\Install\Installer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Carries the development-only state across a destructive import: the local
 * administrator's password, and the settings that point at this environment's
 * external systems.
 *
 * The administrator's password is deliberately never regenerated. Development
 * and production use different credentials on purpose, and an import must not
 * quietly move the production one onto a local machine or hand out a new one.
 * The stored hash is carried over as-is, so the password you already know keeps
 * working.
 */
final class DevStatePreserver
{
    /** Matches the group {@see Installer} puts the first administrator in. */
    private const ADMIN_GROUP_SLUG = 'global-admin';

    /**
     * Settings that name this environment's identity provider or decide whether
     * you can reach the local instance at all. Everything else - event dates,
     * theme, hour rules - is real configuration and comes over from production.
     */
    private const PRESERVED_CONFIG_KEYS = [
        EventConfigStore::KEY_SSO_ROLE_DEPARTMENT_MANAGER,
        EventConfigStore::KEY_SSO_ROLE_SHIFT_MANAGER,
        EventConfigStore::KEY_SSO_ROLE_GLOBAL_ADMIN,
        EventConfigStore::KEY_SSO_ROLE_SUB_ADMIN,
        EventConfigStore::KEY_SSO_BADGE_API_URL,
        EventConfigStore::KEY_PASSWORD_LOGIN_ENABLED,
        EventConfigStore::KEY_ACCESS_MODE,
    ];

    private const TELEGRAM_COLUMNS = ['enabled', 'api_endpoint', 'bot_username', 'api_key', 'updated_at'];

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly GroupRepository $groups,
        private readonly Installer $installer,
    ) {
    }

    /** @param string $adminIdentifier username or email of the local administrator */
    public function capture(string $adminIdentifier): DevStateSnapshot
    {
        $tables = $this->connection->createSchemaManager()->listTableNames();

        return new DevStateSnapshot(
            admin: in_array('users', $tables, true) ? $this->captureAdmin($adminIdentifier) : null,
            eventConfig: in_array('event_config', $tables, true) ? $this->captureConfig() : [],
            telegram: in_array('telegram_configuration', $tables, true) ? $this->captureTelegram() : null,
        );
    }

    /**
     * The identity map is cleared first: rows are written straight through the connection, so
     * anything already hydrated would go on reporting the values it was loaded with.
     *
     * The administrator's password is restored before anything else. It is the one thing that must
     * not be lost if a later step fails, because it is what gets you back into the instance.
     *
     * @return list<string> what was restored, for the command's summary
     */
    public function reapply(DevStateSnapshot $snapshot, bool $grantAdmin): array
    {
        $this->entityManager->clear();

        $notes = [$this->reapplyAdmin($snapshot, $grantAdmin)];

        return array_merge($notes, $this->reapplyConfig($snapshot));
    }

    /** @return array{name: string, email: string, password: string}|null */
    private function captureAdmin(string $identifier): ?array
    {
        /** @var array{name: string, email: string, password: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT name, email, password FROM users WHERE lower(name) = lower(:id) OR lower(email) = lower(:id)',
            ['id' => $identifier],
        );

        return $row === false ? null : $row;
    }

    /** @return array<string, ?string> */
    private function captureConfig(): array
    {
        /** @var array<string, ?string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT config_key, value::text FROM event_config WHERE config_key IN (:keys)',
            ['keys' => self::PRESERVED_CONFIG_KEYS],
            ['keys' => ArrayParameterType::STRING],
        );

        return $rows;
    }

    /**
     * `enabled` is coerced back to a real bool: the driver may hand a PostgreSQL boolean back as
     * 't'/'f', which the later INSERT will not take.
     *
     * @return array<string, mixed>|null
     */
    private function captureTelegram(): ?array
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT %s FROM telegram_configuration ORDER BY id LIMIT 1', implode(', ', self::TELEGRAM_COLUMNS)),
        );
        if ($row === false) {
            return null;
        }

        $row['enabled'] = filter_var($row['enabled'], \FILTER_VALIDATE_BOOL);

        return $row;
    }

    /**
     * The tables are looked up rather than assumed: an old dump restored with --no-migrate need not
     * have them yet.
     *
     * @return list<string>
     */
    private function reapplyConfig(DevStateSnapshot $snapshot): array
    {
        $notes = [];
        $tables = $this->connection->createSchemaManager()->listTableNames();
        $config = in_array('event_config', $tables, true) ? $snapshot->eventConfig : [];

        foreach ($config as $key => $value) {
            $this->connection->executeStatement(
                'INSERT INTO event_config (config_key, value) VALUES (:key, CAST(:value AS JSON))
                 ON CONFLICT (config_key) DO UPDATE SET value = EXCLUDED.value',
                ['key' => $key, 'value' => $value],
            );
        }
        if ($config !== []) {
            $notes[] = sprintf('kept local settings: %s', implode(', ', array_keys($config)));
        }

        if ($snapshot->telegram !== null && in_array('telegram_configuration', $tables, true)) {
            $this->connection->executeStatement('DELETE FROM telegram_configuration');
            $this->connection->insert('telegram_configuration', $snapshot->telegram, ['enabled' => Types::BOOLEAN]);
            $notes[] = 'kept the local Telegram bot configuration';
        }

        return $notes;
    }

    private function reapplyAdmin(DevStateSnapshot $snapshot, bool $grantAdmin): string
    {
        if ($snapshot->admin === null) {
            return 'no local administrator was found before the import - set one up with app:user:password';
        }

        $name = $snapshot->admin['name'];

        $id = $this->connection->fetchOne('SELECT id FROM users WHERE lower(name) = lower(:name)', ['name' => $name]);
        if ($id !== false) {
            $this->connection->executeStatement(
                'UPDATE users SET password = :password WHERE id = :id',
                ['password' => $snapshot->admin['password'], 'id' => $id],
            );
            $note = sprintf('kept the local password of "%s" (imported account)', $name);

            return $grantAdmin ? $note . '; ' . $this->grantAdminGroup((int) $id) : $note;
        }

        $user = $this->createAdmin($name, $snapshot->admin['email'], $snapshot->admin['password']);

        return sprintf('recreated "%s" with its local password and global admin rights', $user->getName());
    }

    private function createAdmin(string $name, string $email, string $passwordHash): User
    {
        $this->installer->seedPrivilegesAndGroups();

        $taken = $this->connection->fetchOne('SELECT 1 FROM users WHERE lower(email) = lower(:email)', ['email' => $email]);
        if ($taken !== false) {
            $email = $name . '@dev.local';
        }

        $user = new User();
        $user->setName($name)
            ->setEmail($email)
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword($passwordHash)
            ->setPersonalData(new PersonalData($user))
            ->setContact(new Contact($user))
            ->setSettings(new Settings($user))
            ->setState(new State($user))
            ->completeOnboarding();

        $group = $this->groups->findOneBySlug(self::ADMIN_GROUP_SLUG);
        if ($group !== null) {
            $user->addGroup($group);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function grantAdminGroup(int $userId): string
    {
        $this->installer->seedPrivilegesAndGroups();

        $group = $this->groups->findOneBySlug(self::ADMIN_GROUP_SLUG);
        if ($group === null) {
            return 'could not grant admin rights: the global-admin group is missing';
        }

        $user = $this->entityManager->find(User::class, $userId);
        if ($user === null) {
            return 'could not grant admin rights: the account disappeared';
        }

        $user->addGroup($group);
        $this->entityManager->flush();

        return 'granted global admin rights';
    }
}
