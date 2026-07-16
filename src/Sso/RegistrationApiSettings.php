<?php

declare(strict_types=1);

namespace App\Sso;

use App\Service\EventConfigStore;

/**
 * The external registration-API endpoint used to look up a user's convention registration
 * number after SSO login. An empty URL simply turns the lookup off.
 */
final class RegistrationApiSettings
{
    public function __construct(private readonly EventConfigStore $config)
    {
    }

    public function apiUrl(): ?string
    {
        $value = trim($this->config->getString(EventConfigStore::KEY_SSO_BADGE_API_URL, ''));

        return $value === '' ? null : $value;
    }

    public function isEnabled(): bool
    {
        return $this->apiUrl() !== null;
    }

    public function save(?string $apiUrl): void
    {
        $this->config->set(EventConfigStore::KEY_SSO_BADGE_API_URL, trim((string) $apiUrl));
        $this->config->flush();
    }
}
