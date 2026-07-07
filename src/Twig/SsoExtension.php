<?php

namespace App\Twig;

use App\Sso\SsoConfig;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Exposes SSO availability to templates (e.g. the login button). */
final class SsoExtension extends AbstractExtension
{
    public function __construct(private readonly SsoConfig $config)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sso_enabled', fn (): bool => $this->config->isEnabled()),
            new TwigFunction('sso_provider_label', fn (): string => $this->config->providerLabel()),
        ];
    }
}
