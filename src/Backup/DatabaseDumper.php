<?php

declare(strict_types=1);

namespace App\Backup;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Process;

/**
 * Produces a PostgreSQL dump of the application database with pg_dump.
 *
 * Custom format is compressed and restores with
 * `pg_restore -d "$DATABASE_URL" --clean --if-exists`. The password is passed
 * via PGPASSWORD, never on the argv. Shared by the scheduled backup command and
 * the admin storage diagnostics so both dump the database the same way.
 */
final class DatabaseDumper
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function dumpTo(string $toFile, float $timeout = 3600.0): void
    {
        $params = $this->connection->getParams();

        $process = new Process(
            [
                'pg_dump',
                '--no-owner',
                '--no-privileges',
                '--format=custom',
                '--file=' . $toFile,
                '--host=' . (string) ($params['host'] ?? 'localhost'),
                '--port=' . (string) ($params['port'] ?? 5432),
                '--username=' . (string) ($params['user'] ?? ''),
                '--dbname=' . (string) ($params['dbname'] ?? ''),
            ],
            env: ['PGPASSWORD' => (string) ($params['password'] ?? '')],
            timeout: $timeout,
        );
        $process->mustRun();
    }
}
