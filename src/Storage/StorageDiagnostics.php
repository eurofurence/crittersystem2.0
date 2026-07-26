<?php

declare(strict_types=1);

namespace App\Storage;

use App\Backup\BackupRetention;
use App\Backup\DatabaseDumper;
use App\Backup\S3BackupStore;
use League\Flysystem\FilesystemOperator;

/**
 * Live connectivity checks for the three storage surfaces, driven from the admin
 * diagnostics page.
 *
 * Uploads and exports are exercised through the app's own already-configured
 * backends (credentials from AWS_* / instance role). The backup bucket is
 * exercised with credentials the admin supplies for the single test and that are
 * never persisted, because the app deliberately does not hold the backup key
 * (see App\Command\BackupDatabaseCommand and deploy/k8s/backup.yaml). The backup
 * probe writes and deletes a dummy object but never reads it back: the backup key
 * is write/list/delete-scoped by design, so a GetObject would fail even when
 * backups are healthy.
 */
final class StorageDiagnostics
{
    /** A recognisable prefix so a probe object left behind by a crash is obvious in a bucket. */
    private const TEST_PREFIX = 'critter-connectivity-test';

    public function __construct(
        private readonly FilesystemOperator $uploads,
        private readonly FilesystemOperator $exports,
        private readonly DatabaseDumper $dumper,
        private readonly string $uploadDsn,
        private readonly string $exportDsn,
    ) {
    }

    /**
     * @return array{scheme: string, bucket: ?string, prefix: ?string, endpoint: ?string, path_style: bool, path: ?string}
     */
    public function describeUploads(): array
    {
        return $this->describe($this->uploadDsn);
    }

    /**
     * @return array{scheme: string, bucket: ?string, prefix: ?string, endpoint: ?string, path_style: bool, path: ?string}
     */
    public function describeExports(): array
    {
        return $this->describe($this->exportDsn);
    }

    /** @return array{ok: bool, steps: list<array{key: string, ok: bool, detail: ?string}>} */
    public function probeUploads(): array
    {
        return $this->probeFilesystem($this->uploads);
    }

    /** @return array{ok: bool, steps: list<array{key: string, ok: bool, detail: ?string}>} */
    public function probeExports(): array
    {
        return $this->probeFilesystem($this->exports);
    }

    /**
     * Round-trip a small object through a filesystem: write, confirm present,
     * read back and compare, then delete. Each step is reported independently so
     * a partial failure (e.g. write works but read is denied) is visible.
     *
     * @return array{ok: bool, steps: list<array{key: string, ok: bool, detail: ?string}>}
     */
    private function probeFilesystem(FilesystemOperator $fs): array
    {
        $key = self::TEST_PREFIX . '-' . bin2hex(random_bytes(8)) . '.txt';
        $payload = 'critter storage connectivity test ' . bin2hex(random_bytes(8));
        $steps = [];
        $written = false;

        try {
            $fs->write($key, $payload);
            $written = true;
            $steps[] = $this->step('admin.storage.step.write', true, null);
        } catch (\Throwable $e) {
            $steps[] = $this->step('admin.storage.step.write', false, $e->getMessage());

            return ['ok' => false, 'steps' => $steps];
        }

        try {
            $present = $fs->fileExists($key);
            $steps[] = $this->step('admin.storage.step.exists', $present, $present ? null : 'Object not found after write.');
        } catch (\Throwable $e) {
            $steps[] = $this->step('admin.storage.step.exists', false, $e->getMessage());
        }

        try {
            $read = $fs->read($key);
            $match = $read === $payload;
            $steps[] = $this->step('admin.storage.step.read', $match, $match ? null : 'Content read back did not match what was written.');
        } catch (\Throwable $e) {
            $steps[] = $this->step('admin.storage.step.read', false, $e->getMessage());
        }

        if ($written) {
            try {
                $fs->delete($key);
                $steps[] = $this->step('admin.storage.step.delete', true, null);
            } catch (\Throwable $e) {
                $steps[] = $this->step('admin.storage.step.delete', false, $e->getMessage());
            }
        }

        return ['ok' => $this->allOk($steps), 'steps' => $steps];
    }

    /**
     * Test the backup destination with credentials supplied for this one run.
     * Dummy object write, confirm, delete; then, if requested and the bucket is
     * reachable, prove pg_dump can produce a dump of this app's database.
     *
     * @return array{ok: bool, steps: list<array{key: string, ok: bool, detail: ?string}>}
     */
    public function probeBackup(
        string $endpoint,
        string $region,
        string $bucket,
        string $prefix,
        bool $pathStyle,
        string $accessKeyId,
        #[\SensitiveParameter] string $secretAccessKey,
        bool $runPgDump,
    ): array {
        $steps = [];

        try {
            $store = new S3BackupStore($endpoint, $region, $bucket, $prefix, $pathStyle, $accessKeyId, $secretAccessKey);
        } catch (\Throwable $e) {
            $steps[] = $this->step('admin.storage.backup.step.dummy', false, $e->getMessage());

            return ['ok' => false, 'steps' => $steps, 'latestBackup' => null];
        }

        $key = self::TEST_PREFIX . '-' . bin2hex(random_bytes(8)) . '.txt';
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            $steps[] = $this->step('admin.storage.backup.step.dummy', false, 'Could not allocate a temporary stream.');

            return ['ok' => false, 'steps' => $steps];
        }

        $reachable = false;
        try {
            fwrite($stream, 'critter backup connectivity test ' . bin2hex(random_bytes(8)));
            rewind($stream);
            $store->put($key, $stream);
            $steps[] = $this->step('admin.storage.step.write', true, null);
            $reachable = true;
        } catch (\Throwable $e) {
            $steps[] = $this->step('admin.storage.step.write', false, $e->getMessage());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($reachable) {
            try {
                $present = $store->exists($key);
                $steps[] = $this->step('admin.storage.step.exists', $present, $present ? null : 'Object not found after upload.');
            } catch (\Throwable $e) {
                $steps[] = $this->step('admin.storage.step.exists', false, $e->getMessage());
            }

            try {
                $store->delete($key);
                $steps[] = $this->step('admin.storage.step.delete', true, null);
            } catch (\Throwable $e) {
                $steps[] = $this->step('admin.storage.step.delete', false, $e->getMessage());
            }
        }

        // Listing is independent of the write round-trip: even if writing failed,
        // an admin still wants to know when the last dump actually landed.
        [$latestBackup, $listStep] = $this->latestBackup($store);
        $steps[] = $listStep;

        if ($runPgDump && $reachable) {
            $steps[] = $this->probePgDump();
        }

        return ['ok' => $this->allOk($steps), 'steps' => $steps, 'latestBackup' => $latestBackup];
    }

    /**
     * Find the most recent successful dump and report whether the list call itself
     * worked. Only the command's dump objects count as backups (see
     * {@see BackupRetention::latest()}); the connectivity-test files this page
     * writes are ignored.
     *
     * @return array{0: array{listed: bool, key: ?string, at: ?\DateTimeImmutable, count: int}, 1: array{key: string, ok: bool, detail: ?string}}
     */
    private function latestBackup(S3BackupStore $store): array
    {
        try {
            $entries = $store->entries();
        } catch (\Throwable $e) {
            return [
                ['listed' => false, 'key' => null, 'at' => null, 'count' => 0],
                $this->step('admin.storage.backup.step.list', false, $e->getMessage()),
            ];
        }

        $okStep = $this->step('admin.storage.backup.step.list', true, null);
        $latest = BackupRetention::latest($entries);
        if ($latest === null) {
            return [['listed' => true, 'key' => null, 'at' => null, 'count' => 0], $okStep];
        }

        return [['listed' => true, 'key' => $latest['key'], 'at' => $latest['at'], 'count' => $latest['count']], $okStep];
    }

    /** @return array{key: string, ok: bool, detail: ?string} */
    private function probePgDump(): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'critter-dbtest-');
        try {
            $this->dumper->dumpTo($file, 120.0);
            $size = (int) filesize($file);
            if ($size === 0) {
                return $this->step('admin.storage.step.pg_dump', false, 'pg_dump produced an empty file.');
            }
            // A custom-format archive starts with the "PGDMP" magic; a mismatch
            // means pg_dump wrote an error or a plain-text file, not a restorable dump.
            $magic = (string) file_get_contents($file, false, null, 0, 5);
            if ($magic !== 'PGDMP') {
                return $this->step('admin.storage.step.pg_dump', false, 'Output is not a valid custom-format dump.');
            }

            return $this->step('admin.storage.step.pg_dump', true, $this->humanBytes($size));
        } catch (\Throwable $e) {
            return $this->step('admin.storage.step.pg_dump', false, $e->getMessage());
        } finally {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Describe a backend from its DSN for display, without ever exposing a credential
     * (the upload/export DSN grammar carries none; credentials come from the environment).
     *
     * @return array{scheme: string, bucket: ?string, prefix: ?string, endpoint: ?string, path_style: bool, path: ?string}
     */
    private function describe(string $dsn): array
    {
        $separator = strpos($dsn, '://');
        $scheme = $separator === false ? '' : strtolower(substr($dsn, 0, $separator));

        if ($scheme === 'local' || $scheme === 'file') {
            $path = $separator === false ? $dsn : substr($dsn, $separator + 3);

            return ['scheme' => $scheme, 'bucket' => null, 'prefix' => null, 'endpoint' => null, 'path_style' => false, 'path' => rtrim($path, '/')];
        }

        parse_str((string) parse_url($dsn, \PHP_URL_QUERY), $query);

        return [
            'scheme' => $scheme,
            'bucket' => ((string) parse_url($dsn, \PHP_URL_HOST)) ?: null,
            'prefix' => isset($query['prefix']) ? trim((string) $query['prefix'], '/') : null,
            'endpoint' => isset($query['endpoint']) ? (string) $query['endpoint'] : null,
            'path_style' => isset($query['path_style']) && filter_var($query['path_style'], \FILTER_VALIDATE_BOOL),
            'path' => null,
        ];
    }

    /**
     * @param list<array{key: string, ok: bool, detail: ?string}> $steps
     */
    private function allOk(array $steps): bool
    {
        foreach ($steps as $step) {
            if (!$step['ok']) {
                return false;
            }
        }

        return $steps !== [];
    }

    /** @return array{key: string, ok: bool, detail: ?string} */
    private function step(string $key, bool $ok, ?string $detail): array
    {
        return ['key' => $key, 'ok' => $ok, 'detail' => $detail !== null ? $this->truncate($detail) : null];
    }

    private function truncate(string $message): string
    {
        $message = trim($message);

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300) . '…' : $message;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            ++$i;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
