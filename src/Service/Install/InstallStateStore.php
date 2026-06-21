<?php

namespace App\Service\Install;

/**
 * Transient, file-backed install/migration state under `var/install/`.
 *
 * Three small files, all NON-transactional on purpose so they survive a rolled
 * back / interrupted migration and can be read while one is running:
 *
 *  - `status.json` — the live migration run state (idle|running|ok|failed) plus
 *    timestamps and the last error. Drives the wizard's progress page.
 *  - `migration.log` — the streamed console output of the running migration so
 *    the operator can follow along.
 *  - `ready.json` — an advisory cache flag, keyed by the latest available
 *    migration version, that lets the per-request maintenance gate skip the
 *    database round-trip in steady state. A new deploy (new version) or a wiped
 *    `var/` directory simply invalidates it; it is never the source of truth.
 *
 * On ephemeral container filesystems these reset on restart, which is fine: the
 * state is always recomputed from the database by {@see MigrationInspector}.
 */
final class InstallStateStore
{
    public const STATE_IDLE = 'idle';
    public const STATE_RUNNING = 'running';
    public const STATE_OK = 'ok';
    public const STATE_FAILED = 'failed';

    private readonly string $dir;

    public function __construct(string $projectDir)
    {
        $this->dir = $projectDir . '/var/install';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    private function statusPath(): string
    {
        return $this->dir . '/status.json';
    }

    private function logPath(): string
    {
        return $this->dir . '/migration.log';
    }

    private function readyPath(): string
    {
        return $this->dir . '/ready.json';
    }

    // ---------------------------------------------------------------- status

    /**
     * Mark the start of a migration run and truncate the previous log.
     */
    public function beginMigration(): void
    {
        $this->ensureDir();
        @file_put_contents($this->logPath(), '');
        $this->writeStatus([
            'state' => self::STATE_RUNNING,
            'startedAt' => gmdate('c'),
            'finishedAt' => null,
            'error' => null,
        ]);
    }

    public function finishMigration(bool $ok, ?string $error = null): void
    {
        $status = $this->readStatus();
        $status['state'] = $ok ? self::STATE_OK : self::STATE_FAILED;
        $status['finishedAt'] = gmdate('c');
        $status['error'] = $error;
        $this->writeStatus($status);
    }

    /**
     * @return array{state: string, startedAt: ?string, finishedAt: ?string, error: ?string}
     */
    public function readStatus(): array
    {
        $default = ['state' => self::STATE_IDLE, 'startedAt' => null, 'finishedAt' => null, 'error' => null];
        $raw = @file_get_contents($this->statusPath());
        if ($raw === false || $raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? array_merge($default, $decoded) : $default;
    }

    /**
     * @param array{state: string, startedAt: ?string, finishedAt: ?string, error: ?string} $status
     */
    private function writeStatus(array $status): void
    {
        $this->ensureDir();
        @file_put_contents($this->statusPath(), json_encode($status, \JSON_PRETTY_PRINT));
    }

    public function resetStatus(): void
    {
        @unlink($this->statusPath());
        @unlink($this->logPath());
    }

    // ------------------------------------------------------------------- log

    public function appendLog(string $text): void
    {
        $this->ensureDir();
        @file_put_contents($this->logPath(), $text, \FILE_APPEND);
    }

    public function readLog(): string
    {
        $raw = @file_get_contents($this->logPath());

        return $raw === false ? '' : $raw;
    }

    // ----------------------------------------------------------------- ready

    /**
     * Record that the schema is up to date for the given migration version so
     * the gate can fast-path subsequent requests.
     */
    public function markReady(?string $version): void
    {
        $this->ensureDir();
        @file_put_contents($this->readyPath(), json_encode([
            'ready' => true,
            'version' => $version,
            'at' => gmdate('c'),
        ], \JSON_PRETTY_PRINT));
    }

    public function clearReady(): void
    {
        @unlink($this->readyPath());
    }

    /**
     * True only when the cached flag exists AND matches the currently shipped
     * latest version — otherwise the caller must do a full database check.
     */
    public function isReadyFor(?string $latestVersion): bool
    {
        $raw = @file_get_contents($this->readyPath());
        if ($raw === false || $raw === '') {
            return false;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded)
            && ($decoded['ready'] ?? false) === true
            && ($decoded['version'] ?? null) === $latestVersion;
    }
}
