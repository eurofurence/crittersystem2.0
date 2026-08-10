<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Builds the backup bucket client from the BACKUP_S3_* environment.
 *
 * These credentials are deliberately separate from the app's own storage DSNs
 * (see {@see S3BackupStore}); reading them in one place keeps the scheduled
 * backup and the development import pointing at the same bucket.
 */
final class BackupStoreFactory
{
    public function bucket(): string
    {
        return $this->env('BACKUP_S3_BUCKET');
    }

    public function create(): S3BackupStore
    {
        return new S3BackupStore(
            endpoint: $this->env('BACKUP_S3_ENDPOINT'),
            region: $this->env('BACKUP_S3_REGION'),
            bucket: $this->bucket(),
            prefix: $this->env('BACKUP_S3_PREFIX'),
            pathStyle: filter_var($this->env('BACKUP_S3_PATH_STYLE'), \FILTER_VALIDATE_BOOL),
            accessKeyId: $this->env('BACKUP_S3_ACCESS_KEY_ID'),
            secretAccessKey: $this->env('BACKUP_S3_SECRET_ACCESS_KEY'),
        );
    }

    public function env(string $key): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? trim($value) : '';
    }
}
