<?php

namespace App\Storage;

use League\Flysystem\FilesystemOperator;

/**
 * Application-facing wrapper over the uploads filesystem. Every user upload
 * (chat images, avatars, …) goes through here, so callers never depend on the
 * concrete backend — local folder in development, S3 in production, selected by
 * the UPLOAD_STORAGE_DSN env var (see {@see FileStorageFactory}).
 *
 * Stored keys are backend-relative (e.g. "avatars/ab/cd.png"); the same key
 * resolves under either backend. Files are private and must be served through
 * an authorization-checked controller — never a public bucket URL.
 */
final class FileStorage
{
    public function __construct(private readonly FilesystemOperator $uploads)
    {
    }

    public function write(string $key, string $contents, ?string $mimeType = null): void
    {
        $this->uploads->write($key, $contents, $mimeType !== null ? ['mimetype' => $mimeType] : []);
    }

    /**
     * @param resource $resource
     */
    public function writeStream(string $key, $resource, ?string $mimeType = null): void
    {
        $this->uploads->writeStream($key, $resource, $mimeType !== null ? ['mimetype' => $mimeType] : []);
    }

    public function read(string $key): string
    {
        return $this->uploads->read($key);
    }

    /**
     * @return resource
     */
    public function readStream(string $key)
    {
        return $this->uploads->readStream($key);
    }

    public function exists(string $key): bool
    {
        return $this->uploads->fileExists($key);
    }

    public function delete(string $key): void
    {
        $this->uploads->delete($key);
    }

    public function mimeType(string $key): string
    {
        return $this->uploads->mimeType($key);
    }

    public function fileSize(string $key): int
    {
        return $this->uploads->fileSize($key);
    }
}
