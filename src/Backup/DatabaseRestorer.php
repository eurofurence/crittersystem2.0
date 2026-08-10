<?php

declare(strict_types=1);

namespace App\Backup;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Loads a {@see DatabaseDumper} dump into the connected database, destroying
 * whatever was there. Only ever aimed at a development database - the caller is
 * responsible for refusing to run outside dev.
 *
 * The schema is dropped and recreated rather than restored over: a dump taken
 * from an older release does not contain the tables a newer branch has added,
 * so restoring "on top" would leave stale tables behind and make the migration
 * run afterwards disagree with the schema it finds.
 *
 * pg_restore may not be newer than the server. It emits the session settings of
 * its own version (PostgreSQL 17 added `transaction_timeout`), and an older
 * server rejects the ones it does not know, aborting the restore. Alpine keeps
 * versioned clients side by side, so the matching one is preferred when
 * present; {@see clientProblem()} must be consulted before {@see wipe()},
 * because a mismatch found afterwards leaves an empty database behind.
 */
final class DatabaseRestorer
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** A human-readable reason why a restore cannot run, or null when it can. */
    public function clientProblem(): ?string
    {
        $binary = $this->binary();
        if ($binary === null) {
            return 'pg_restore was not found on this machine.';
        }

        $client = $this->majorOf($binary);
        $server = $this->serverMajor();
        if ($client !== null && $client > $server) {
            return sprintf(
                'pg_restore %d cannot load into a PostgreSQL %d server; it uses settings the older server rejects. '
                . 'Install postgresql%d-client (Alpine keeps it at /usr/libexec/postgresql%d/) or point at a %d server.',
                $client,
                $server,
                $server,
                $server,
                $client,
            );
        }

        return null;
    }

    /** host/database of the connection, for a confirmation prompt. */
    public function target(): string
    {
        $params = $this->connection->getParams();

        return sprintf(
            '%s@%s:%s/%s (PostgreSQL %d)',
            (string) ($params['user'] ?? ''),
            (string) ($params['host'] ?? 'localhost'),
            (string) ($params['port'] ?? 5432),
            (string) ($params['dbname'] ?? ''),
            $this->serverMajor(),
        );
    }

    /**
     * Drops every object in the public schema.
     *
     * Other sessions are terminated first: a running dev server or worker holds
     * open connections whose locks would make the DROP wait indefinitely. They
     * reconnect on their next request.
     */
    public function wipe(): void
    {
        $this->connection->executeStatement(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity
             WHERE datname = current_database() AND pid <> pg_backend_pid()'
        );
        $this->connection->executeStatement('DROP SCHEMA public CASCADE');
        $this->connection->executeStatement('CREATE SCHEMA public');
    }

    public function restore(string $fromFile, float $timeout = 3600.0): void
    {
        $binary = $this->binary();
        if ($binary === null) {
            throw new \RuntimeException('pg_restore was not found.');
        }

        $params = $this->connection->getParams();

        $process = new Process(
            [
                $binary,
                '--no-owner',
                '--no-privileges',
                '--single-transaction',
                '--exit-on-error',
                '--host=' . (string) ($params['host'] ?? 'localhost'),
                '--port=' . (string) ($params['port'] ?? 5432),
                '--username=' . (string) ($params['user'] ?? ''),
                '--dbname=' . (string) ($params['dbname'] ?? ''),
                $fromFile,
            ],
            env: ['PGPASSWORD' => (string) ($params['password'] ?? '')],
            timeout: $timeout,
        );
        $process->mustRun();
    }

    private function binary(): ?string
    {
        $versioned = sprintf('/usr/libexec/postgresql%d/pg_restore', $this->serverMajor());
        if (is_executable($versioned)) {
            return $versioned;
        }

        return (new ExecutableFinder())->find('pg_restore');
    }

    private function serverMajor(): int
    {
        return (int) (string) $this->connection->fetchOne('SHOW server_version');
    }

    private function majorOf(string $binary): ?int
    {
        $process = new Process([$binary, '--version']);
        $process->run();
        if (!$process->isSuccessful() || preg_match('/\s(\d+)[.\s]/', $process->getOutput(), $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }
}
