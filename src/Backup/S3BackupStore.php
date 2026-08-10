<?php

declare(strict_types=1);

namespace App\Backup;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;

/**
 * The S3-compatible bucket database dumps are uploaded to.
 *
 * Credentials are passed in explicitly and NEVER read from the ambient AWS_*
 * environment: the backup destination is a separate bucket with its own
 * write-scoped key, so a compromise of the app's storage credentials cannot
 * reach the backups and vice versa. Path-style addressing is configurable
 * because self-hosted S3 (MinIO/Ceph/Garage) requires it while AWS rejects it.
 */
final class S3BackupStore
{
    private readonly FilesystemOperator $fs;

    public function __construct(
        string $endpoint,
        string $region,
        string $bucket,
        string $prefix,
        bool $pathStyle,
        string $accessKeyId,
        #[\SensitiveParameter] string $secretAccessKey,
    ) {
        if ($bucket === '') {
            throw new \InvalidArgumentException('A backup bucket is required.');
        }

        $config = [
            'region' => $region !== '' ? $region : 'us-east-1',
            'pathStyleEndpoint' => $pathStyle,
        ];
        if ($endpoint !== '') {
            $config['endpoint'] = $endpoint;
        }
        if ($accessKeyId !== '') {
            $config['accessKeyId'] = $accessKeyId;
            $config['accessKeySecret'] = $secretAccessKey;
        }

        $this->fs = new Filesystem(new AsyncAwsS3Adapter(new S3Client($config), $bucket, trim($prefix, '/')));
    }

    /** @param resource $stream */
    public function put(string $key, $stream): void
    {
        $this->fs->writeStream($key, $stream);
    }

    public function exists(string $key): bool
    {
        return $this->fs->fileExists($key);
    }

    /** Streams a stored dump to a local file rather than buffering it in memory. */
    public function readTo(string $key, string $toFile): void
    {
        $source = $this->fs->readStream($key);
        $target = fopen($toFile, 'wb');
        if ($target === false) {
            throw new \RuntimeException(sprintf('Could not open "%s" for writing.', $toFile));
        }

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($target);
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    public function delete(string $key): void
    {
        $this->fs->delete($key);
    }

    /**
     * Every stored dump with its modification time, for retention decisions.
     *
     * @return list<array{key: string, lastModified: ?int}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->fs->listContents('', false) as $item) {
            if ($item->isFile()) {
                $entries[] = ['key' => $item->path(), 'lastModified' => $item->lastModified()];
            }
        }

        return $entries;
    }
}
