<?php

namespace App\Service\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * A cross-process migration lock built on PostgreSQL session-level advisory
 * locks (`pg_advisory_lock`).
 *
 * Why advisory locks are the self-healing core of safe auto-migration:
 *
 *  - They are held for the lifetime of the database *session* (connection). If
 *    the migrating pod/container is killed mid-run, its connection drops and
 *    PostgreSQL releases the lock automatically - no stale lock file to clear,
 *    no manual `--force` to remember. The next pod simply acquires it and
 *    proceeds.
 *  - When many replicas boot at once, exactly one wins the lock and migrates;
 *    the others block until it finishes and then find nothing pending (Doctrine
 *    migrations are idempotent), so they exit cleanly. No double-runs.
 *
 * On non-PostgreSQL platforms the lock degrades to a no-op (best effort).
 */
final class MigrationLock
{
    /** Arbitrary but stable 64-bit key shared by every process that migrates. */
    private const LOCK_KEY = 4727140119;

    private bool $held = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    private function supportsAdvisoryLocks(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    /**
     * Try to grab the lock without blocking.
     *
     * @return bool true if acquired (or unsupported), false if another process holds it
     */
    public function tryAcquire(): bool
    {
        if (!$this->supportsAdvisoryLocks()) {
            $this->held = true;

            return true;
        }

        $got = (bool) $this->connection->fetchOne('SELECT pg_try_advisory_lock(?)', [self::LOCK_KEY]);
        $this->held = $got;

        return $got;
    }

    /**
     * Block until the lock is acquired. Used by the startup/CLI path so a fleet
     * of replicas serialises cleanly around a single migrator.
     */
    public function acquire(): void
    {
        if (!$this->supportsAdvisoryLocks()) {
            $this->held = true;

            return;
        }

        $this->connection->executeQuery('SELECT pg_advisory_lock(?)', [self::LOCK_KEY]);
        $this->held = true;
    }

    /**
     * A failure to unlock is ignored: it means the connection is already gone, and PostgreSQL
     * releases an advisory lock with the session that took it.
     */
    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        $this->held = false;

        if (!$this->supportsAdvisoryLocks()) {
            return;
        }

        try {
            $this->connection->executeQuery('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
        } catch (\Throwable) {
        }
    }

    public function isHeld(): bool
    {
        return $this->held;
    }
}
