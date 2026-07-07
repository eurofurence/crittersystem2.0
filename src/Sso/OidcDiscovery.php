<?php

declare(strict_types=1);

namespace App\Sso;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves the OIDC endpoints, preferring automatic discovery from the
 * provider's .well-known/openid-configuration and falling back to the manually
 * configured endpoints.
 */
final class OidcDiscovery
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SsoConfig $config,
    ) {
    }

    /**
     * @return array{issuer: string, authorization: string, token: string, userinfo: string}|null
     */
    public function endpoints(): ?array
    {
        if ($this->config->usesDiscovery()) {
            try {
                $data = $this->httpClient->request('GET', $this->config->discoveryUrl())->toArray();

                return [
                    'issuer' => (string) ($data['issuer'] ?? ''),
                    'authorization' => (string) ($data['authorization_endpoint'] ?? ''),
                    'token' => (string) ($data['token_endpoint'] ?? ''),
                    'userinfo' => (string) ($data['userinfo_endpoint'] ?? ''),
                ];
            } catch (\Throwable) {
                return null;
            }
        }

        $manual = $this->config->manualEndpoints();
        if ($manual['authorization'] === '') {
            return null;
        }

        return ['issuer' => '', 'authorization' => $manual['authorization'], 'token' => $manual['token'], 'userinfo' => $manual['userinfo']];
    }

    /** @return array{ok: bool, source: string, error: ?string, endpoints: ?array} */
    public function status(): array
    {
        $source = $this->config->usesDiscovery() ? 'discovery' : 'manual';
        $endpoints = $this->endpoints();

        return [
            'ok' => $endpoints !== null && $endpoints['authorization'] !== '',
            'source' => $source,
            'error' => $endpoints === null ? 'Could not resolve OIDC endpoints.' : null,
            'endpoints' => $endpoints,
        ];
    }
}
