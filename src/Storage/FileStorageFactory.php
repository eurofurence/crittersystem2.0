<?php

namespace App\Storage;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;

/**
 * Builds the uploads filesystem from a single DSN so the backend is chosen
 * purely by the UPLOAD_STORAGE_DSN environment variable — a local folder in
 * development, S3 (or an S3-compatible service) in production — with no code
 * change to switch.
 *
 *   local://var/uploads
 *   local:///absolute/path
 *   s3://my-bucket?region=eu-central-1&prefix=uploads&endpoint=https://...&path_style=1
 *
 * S3 credentials are resolved by async-aws from the standard AWS_* environment
 * variables or an instance role; they are never read from the DSN.
 */
final class FileStorageFactory
{
    public function __construct(private readonly string $projectDir)
    {
    }

    public function create(string $dsn): FilesystemOperator
    {
        // parse_url() rejects the empty-authority form (local:///abs/path), so
        // read the scheme directly off the DSN.
        $separator = strpos($dsn, '://');
        $scheme = $separator === false ? '' : strtolower(substr($dsn, 0, $separator));

        return match ($scheme) {
            'local', 'file' => $this->createLocal($dsn),
            's3' => $this->createS3($dsn),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported UPLOAD_STORAGE_DSN scheme "%s"; use local:// or s3://.',
                $scheme,
            )),
        };
    }

    private function createLocal(string $dsn): FilesystemOperator
    {
        $position = strpos($dsn, '://');
        $path = $position === false ? $dsn : substr($dsn, $position + 3);
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = 'var/uploads';
        }
        if (!str_starts_with($path, '/')) {
            $path = $this->projectDir . '/' . $path;
        }

        return new Filesystem(new LocalFilesystemAdapter(
            $path,
            PortableVisibilityConverter::fromArray([], Visibility::PRIVATE),
        ));
    }

    private function createS3(string $dsn): FilesystemOperator
    {
        $bucket = (string) parse_url($dsn, \PHP_URL_HOST);
        if ($bucket === '') {
            throw new \InvalidArgumentException('S3 UPLOAD_STORAGE_DSN must include a bucket, e.g. s3://bucket?region=eu-central-1');
        }

        parse_str((string) parse_url($dsn, \PHP_URL_QUERY), $query);

        $config = [];
        if (!empty($query['region'])) {
            $config['region'] = (string) $query['region'];
        }
        if (!empty($query['endpoint'])) {
            $config['endpoint'] = (string) $query['endpoint'];
        }
        if (!empty($query['path_style'])) {
            $config['pathStyleEndpoint'] = filter_var($query['path_style'], \FILTER_VALIDATE_BOOL);
        }

        $prefix = isset($query['prefix']) ? trim((string) $query['prefix'], '/') : '';

        return new Filesystem(new AsyncAwsS3Adapter(new S3Client($config), $bucket, $prefix));
    }
}
