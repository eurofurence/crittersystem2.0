<?php

namespace App\Twig;

use App\Service\PiiMasker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig helpers for masking personally identifiable information from users who
 * lack the user:pii:view permission.
 *
 *   {{ user.email|pii_email }}     {{ user.contact.mobile|pii }}
 *   {% if can_see_pii() %}…{% endif %}
 */
final class PiiExtension extends AbstractExtension
{
    public function __construct(private readonly PiiMasker $masker)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('pii_email', $this->masker->email(...)),
            new TwigFilter('pii', $this->masker->generic(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can_see_pii', $this->masker->canSeePii(...)),
        ];
    }
}
