<?php

declare(strict_types=1);

namespace App\Storage;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The filesystem export archives live on - audit legal packages and GDPR data exports.
 *
 * Kept separate from {@see FileStorage} (user uploads) because the two have different retention and
 * sensitivity: exports are short-lived bundles of personal data that a purge command deletes on a
 * schedule, and an operator will usually want them in a different bucket with its own lifecycle
 * policy. The backend is chosen by EXPORT_STORAGE_DSN, exactly as uploads are chosen by
 * UPLOAD_STORAGE_DSN.
 *
 * Exports MUST go through here rather than to a container-local path: the messenger worker writes
 * GDPR exports and a *different* process serves the download, so anything written to a local
 * directory is invisible to the reader and is destroyed by the next deploy.
 *
 * Keys are backend-relative (e.g. "audit/{uuid}.zip"). Archives are private and are only ever served
 * by an authorization-checked controller - never from a public bucket URL.
 */
final class ExportStorage
{
    public function __construct(private readonly FilesystemOperator $exports)
    {
    }

    public function write(string $key, string $contents): void
    {
        $this->exports->write($key, $contents, ['mimetype' => 'application/zip']);
    }

    public function read(string $key): string
    {
        return $this->exports->read($key);
    }

    /** @return resource */
    public function readStream(string $key)
    {
        return $this->exports->readStream($key);
    }

    public function exists(string $key): bool
    {
        return $this->exports->fileExists($key);
    }

    /**
     * Every key under a prefix, as a lookup set.
     *
     * For listing a page of exports, this is one round trip where calling exists() per row would be one
     * per row - against S3 that is a HEAD request each.
     *
     * @return array<string, true>
     */
    public function keysUnder(string $prefix): array
    {
        $keys = [];
        foreach ($this->exports->listContents($prefix, false) as $item) {
            if ($item->isFile()) {
                $keys[$item->path()] = true;
            }
        }

        return $keys;
    }

    public function fileSize(string $key): int
    {
        return $this->exports->fileSize($key);
    }

    /** Idempotent: deleting a key that is already gone is not an error. */
    public function delete(string $key): void
    {
        $this->exports->delete($key);
    }

    /**
     * Streams the archive as an attachment. The backend may be object storage with no local path, so
     * this cannot be a BinaryFileResponse; streaming also keeps a large export out of memory.
     * Callers must have authorized the download and confirmed the key still exists.
     */
    public function download(string $key, string $filename): StreamedResponse
    {
        $stream = $this->readStream($key);

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        });
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Length', (string) $this->fileSize($key));
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename),
        );

        return $response;
    }
}
