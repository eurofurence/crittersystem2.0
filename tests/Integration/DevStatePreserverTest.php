<?php

namespace App\Tests\Integration;

use App\Backup\DevStatePreserver;
use App\Entity\PersonalData;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EventConfigStore;
use App\Tests\DatabaseTestCase;
use Doctrine\DBAL\Connection;

/**
 * The local administrator's password must survive an import of production data.
 * Development and production credentials differ deliberately, so an import that
 * moved production's hash over - or minted a new password - would either hand
 * out a production credential or lock the developer out of their own instance.
 *
 * Each test captures a snapshot, then overwrites the rows the way a restore
 * would, and checks what comes back.
 */
final class DevStatePreserverTest extends DatabaseTestCase
{
    private const LOCAL_HASH = '$2y$13$localLocalLocalLocalLocalLocalLocalLocalLocalLocalLocalL';
    private const IMPORTED_HASH = '$2y$13$importedImportedImportedImportedImportedImportedImported';

    private function preserver(): DevStatePreserver
    {
        /** @var DevStatePreserver $preserver */
        $preserver = static::getContainer()->get(DevStatePreserver::class);

        return $preserver;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }

    private function admin(string $password): User
    {
        $user = new User();
        $user->setName('admin')
            ->setEmail('admin@localhost')
            ->setApiKey(bin2hex(random_bytes(16)))
            ->setPassword($password);
        $user->setPersonalData(new PersonalData($user));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        return $user;
    }

    private function hashOf(string $name): string|false
    {
        return $this->connection()->fetchOne('SELECT password FROM users WHERE name = ?', [$name]);
    }

    public function testItPutsTheLocalPasswordBackOnTheImportedAccount(): void
    {
        $this->admin(self::LOCAL_HASH);
        $snapshot = $this->preserver()->capture('admin');

        // The import brings its own account of the same name.
        $this->connection()->executeStatement('UPDATE users SET password = ? WHERE name = ?', [self::IMPORTED_HASH, 'admin']);

        $this->preserver()->reapply($snapshot, false);

        self::assertSame(self::LOCAL_HASH, $this->hashOf('admin'));
    }

    public function testItRecreatesTheAdministratorWhenTheImportHasNoSuchAccount(): void
    {
        $this->admin(self::LOCAL_HASH);
        $snapshot = $this->preserver()->capture('admin');

        $this->connection()->executeStatement('DELETE FROM users WHERE name = ?', ['admin']);

        $this->preserver()->reapply($snapshot, false);

        self::assertSame(self::LOCAL_HASH, $this->hashOf('admin'));

        /** @var UserRepository $users */
        $users = static::getContainer()->get(UserRepository::class);
        $recreated = $users->findOneByUsernameOrEmail('admin');
        self::assertNotNull($recreated);
        self::assertTrue($recreated->hasPrivilege('global:admin'), 'the recreated account must be able to administer the instance');
    }

    public function testItCanBeFoundByEmailAndKeepsTheAccountUsable(): void
    {
        $this->admin(self::LOCAL_HASH);

        $snapshot = $this->preserver()->capture('admin@localhost');

        self::assertNotNull($snapshot->admin);
        self::assertSame('admin', $snapshot->admin['name']);
    }

    public function testItSaysSoWhenThereIsNoLocalAdministratorToCarryOver(): void
    {
        $snapshot = $this->preserver()->capture('admin');
        self::assertNull($snapshot->admin);

        $notes = $this->preserver()->reapply($snapshot, false);

        self::assertStringContainsString('app:user:password', implode(' ', $notes));
    }

    public function testItKeepsTheSettingsThatNameThisEnvironmentAndImportsTheRest(): void
    {
        /** @var EventConfigStore $config */
        $config = static::getContainer()->get(EventConfigStore::class);
        $config->set(EventConfigStore::KEY_SSO_ROLE_GLOBAL_ADMIN, 'LOCAL-IDP-ROLE');
        $config->set(EventConfigStore::KEY_NAME, 'Local Event');
        $this->em->flush();

        $snapshot = $this->preserver()->capture('admin');

        $config->set(EventConfigStore::KEY_SSO_ROLE_GLOBAL_ADMIN, 'PRODUCTION-IDP-ROLE');
        $config->set(EventConfigStore::KEY_NAME, 'Production Event');
        $this->em->flush();

        $this->preserver()->reapply($snapshot, false);
        $config->reset();

        self::assertSame('LOCAL-IDP-ROLE', $config->get(EventConfigStore::KEY_SSO_ROLE_GLOBAL_ADMIN));
        self::assertSame('Production Event', $config->get(EventConfigStore::KEY_NAME), 'event configuration is real data and comes from production');
    }
}
