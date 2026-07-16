<?php

namespace App\Service\Install;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Psr\Log\LoggerInterface;

/**
 * Read-only, Doctrine-native answers to "does this deployment need attention?".
 *
 * The single source of truth for "is a migration pending" is Doctrine's own
 * comparison of the available migration classes against the
 * `doctrine_migration_versions` bookkeeping table — NOT a marker file. That is
 * the self-healing property we want: there is no `.ok` flag that can drift out
 * of sync with reality after a crashed or interrupted deploy. State is always
 * recomputed from the database + the shipped migration set.
 *
 * {@see InstallStateStore} adds a thin, *advisory* cache flag on top purely to
 * keep the per-request gate cheap; it is keyed by the latest available
 * migration version so a new deploy automatically invalidates it.
 */
final class MigrationInspector
{
    public function __construct(
        private readonly DependencyFactory $dependencyFactory,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Can we open a connection and run a trivial query against the database? */
    public function isDatabaseReachable(): bool
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable $e) {
            /*
             * A false here seals the whole site behind the maintenance page, and every cause — server
             * down, wrong password, wrong database name, TLS refused — produces that same blank wall.
             * The reason exists only in this exception.
             */
            $this->logger->error('Database is unreachable: {reason}', [
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Number of migration classes that have not yet been executed.
     *
     * Returns the full available count when the bookkeeping table does not yet
     * exist (a brand-new database), which is exactly the "first install"
     * situation. Throws nothing — callers gate on {@see isDatabaseReachable()}
     * first to distinguish "DB down" from "DB empty".
     */
    public function pendingMigrationCount(): int
    {
        try {
            return \count($this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations());
        } catch (\Throwable $e) {
            /*
             * Usually the doctrine_migration_versions table simply does not exist yet, so every shipped
             * migration is pending — the normal first-install path. A misconfigured migrations setup also
             * lands here and is indistinguishable from a fresh database, so record which one it was.
             */
            $this->logger->info('Migration status unavailable, treating every migration as pending: {reason}', [
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            return \count($this->dependencyFactory->getMigrationRepository()->getMigrations());
        }
    }

    /**
     * The highest available migration version shipped with this code (filesystem
     * only — no database access), or null when there are no migrations.
     */
    public function latestAvailableVersion(): ?string
    {
        $items = $this->dependencyFactory->getMigrationRepository()->getMigrations()->getItems();
        if ($items === []) {
            return null;
        }

        $last = $items[\count($items) - 1];

        return (string) $last->getVersion();
    }

    /** True when at least one user row exists (schema must already be migrated). */
    public function hasAnyUser(): bool
    {
        try {
            return (bool) $this->connection->fetchOne('SELECT 1 FROM users LIMIT 1');
        } catch (\Throwable $e) {
            // Expected before the schema exists; anything else here is worth seeing.
            $this->logger->debug('Could not read the users table: {reason}', ['reason' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The maintenance gate trigger: the public site must be sealed when the DB
     * is unreachable or the schema is behind the shipped migrations.
     */
    public function isMigrationNeeded(): bool
    {
        return !$this->isDatabaseReachable() || $this->pendingMigrationCount() > 0;
    }

    /**
     * A migrated, reachable database that has no users yet — the "first run"
     * where an admin account still has to be created.
     */
    public function isFreshInstall(): bool
    {
        return $this->isDatabaseReachable()
            && $this->pendingMigrationCount() === 0
            && !$this->hasAnyUser();
    }

    /**
     * Whether the /admin/install wizard has anything to do. When false the
     * wizard route must redirect back to the site.
     */
    public function isInstallNeeded(): bool
    {
        return $this->isMigrationNeeded() || $this->isFreshInstall();
    }
}
