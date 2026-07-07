<?php

declare(strict_types=1);

namespace App\Sso;

use League\OAuth2\Client\Provider\GenericProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Builds a configured league OAuth2 GenericProvider for the OIDC login flow. */
final class OidcProviderFactory
{
    public function __construct(
        private readonly SsoConfig $config,
        private readonly OidcDiscovery $discovery,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function create(): GenericProvider
    {
        $endpoints = $this->discovery->endpoints();
        if ($endpoints === null) {
            throw new \RuntimeException('OIDC endpoints are not available.');
        }

        return new GenericProvider([
            'clientId' => $this->config->clientId(),
            'clientSecret' => $this->config->clientSecret(),
            'redirectUri' => $this->redirectUri(),
            'urlAuthorize' => $endpoints['authorization'],
            'urlAccessToken' => $endpoints['token'],
            'urlResourceOwnerDetails' => $endpoints['userinfo'],
            'scopes' => implode(' ', $this->config->scopes()),
        ]);
    }

    public function redirectUri(): string
    {
        return $this->urlGenerator->generate('app_sso_check', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
