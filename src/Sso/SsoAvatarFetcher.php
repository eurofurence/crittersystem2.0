<?php

declare(strict_types=1);

namespace App\Sso;

use App\Entity\User;
use App\Storage\FileStorage;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads the avatar the SSO provider advertises and stores it in the local uploads filesystem,
 * so it is served through the same authorization-checked route as user uploads
 * ({@see \App\Controller\MediaController::avatar}) rather than hot-linked from the identity
 * provider. Returns the storage key, or null when the picture cannot be fetched or is not an
 * image - the caller keeps whatever avatar already exists in that case.
 */
final class SsoAvatarFetcher
{
    private const MAX_BYTES = 3_145_728; // 3 MiB

    /** @var array<int, string> imagetype constant => file extension */
    private const EXTENSIONS = [
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FileStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The bytes decide the type, not the advertised Content-Type: only what is genuinely an image
     * is stored.
     *
     * @return string|null the storage key the picture was written under, or null on any failure
     */
    public function fetchAndStore(string $url, User $user): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 5,
                'max_duration' => 10,
                'headers' => ['Accept' => 'image/*'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $content = $response->getContent();
            if ($content === '' || strlen($content) > self::MAX_BYTES) {
                return null;
            }

            $info = @getimagesizefromstring($content);
            $extension = $info !== false ? (self::EXTENSIONS[$info[2]] ?? null) : null;
            if ($extension === null) {
                return null;
            }

            $key = sprintf('avatars/%s/sso-%s.%s', $user->getUuid(), bin2hex(random_bytes(8)), $extension);
            $this->storage->write($key, $content, $info['mime'] ?? null);

            return $key;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not download SSO avatar from {url}: {reason}', [
                'url' => $url,
                'reason' => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }
}
