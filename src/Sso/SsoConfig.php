<?php

declare(strict_types=1);

namespace App\Sso;

/** Reads the OIDC SSO configuration from the environment (Infisical in prod). */
final class SsoConfig
{
    public function __construct(
        private readonly bool $ssoEnabled,
        private readonly string $ssoDiscoveryUrl,
        private readonly string $ssoClientId,
        #[\SensitiveParameter]
        private readonly string $ssoClientSecret,
        private readonly string $ssoAuthorizationUrl,
        private readonly string $ssoTokenUrl,
        private readonly string $ssoUserinfoUrl,
        private readonly string $ssoScopes,
        private readonly string $ssoProviderLabel,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->ssoEnabled && $this->ssoClientId !== '';
    }

    public function clientId(): string
    {
        return $this->ssoClientId;
    }

    public function clientSecret(): string
    {
        return $this->ssoClientSecret;
    }

    public function discoveryUrl(): string
    {
        return $this->ssoDiscoveryUrl;
    }

    public function usesDiscovery(): bool
    {
        return $this->ssoDiscoveryUrl !== '';
    }

    /** @return array{authorization: string, token: string, userinfo: string} */
    public function manualEndpoints(): array
    {
        return [
            'authorization' => $this->ssoAuthorizationUrl,
            'token' => $this->ssoTokenUrl,
            'userinfo' => $this->ssoUserinfoUrl,
        ];
    }

    /** @return string[] */
    public function scopes(): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($this->ssoScopes)) ?: []));
    }

    public function providerLabel(): string
    {
        return $this->ssoProviderLabel !== '' ? $this->ssoProviderLabel : 'oidc';
    }

    /** Client id truncated for safe display on the status page. */
    public function truncatedClientId(): string
    {
        $id = $this->ssoClientId;
        if (strlen($id) <= 6) {
            return $id === '' ? '(unset)' : str_repeat('•', strlen($id));
        }

        return substr($id, 0, 3).str_repeat('•', 6).substr($id, -2);
    }
}
