<?php

namespace App\Service\Chat;

use App\Storage\FileStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores chat image attachments in the pluggable upload storage under a `chat/`
 * prefix. Validates that the upload is a reasonably sized image.
 */
final class ChatImageStore
{
    private const MAX_BYTES = 5_000_000;
    private const ALLOWED = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(private readonly FileStorage $storage)
    {
    }

    /**
     * Validate and store an uploaded image, returning its storage key.
     *
     * @throws \RuntimeException on an invalid file
     */
    public function store(UploadedFile $file): string
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw new \RuntimeException('The image is missing or too large (max 5 MB).');
        }
        $mime = (string) $file->getMimeType();
        if (!\in_array($mime, self::ALLOWED, true)) {
            throw new \RuntimeException('Only PNG, JPEG, GIF or WebP images are allowed.');
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $key = 'chat/'.bin2hex(random_bytes(16)).'.'.$ext;
        $this->storage->write($key, (string) file_get_contents($file->getPathname()), $mime);

        return $key;
    }
}
