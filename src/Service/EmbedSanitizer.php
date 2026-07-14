<?php

namespace App\Service;

use App\Entity\Location;

/**
 * Produces a safe embedded-map iframe for a location. A map URL is
 * wrapped in an application-controlled iframe; an approved iframe snippet has its
 * src extracted and rebuilt. In both cases the host must be on the environment
 * allowlist, which also drives the frame-src CSP directive.
 */
class EmbedSanitizer
{
    /** @var string[] */
    private array $allowedHosts;

    public function __construct(string $allowedDomains)
    {
        $this->allowedHosts = array_values(array_filter(array_map(
            static fn (string $d) => strtolower(trim($d)),
            explode(',', $allowedDomains),
        )));
    }

    /** @return string[] */
    public function allowedHosts(): array
    {
        return $this->allowedHosts;
    }

    public function isAllowedUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        if ($host === '' || parse_url($url, \PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        foreach ($this->allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A sanitized iframe (as a src URL to render) for the location, or null when
     * no valid, allowed embed is configured.
     */
    public function embedSrc(Location $location): ?string
    {
        if ($location->getEmbedHtml() !== null && preg_match('/<iframe[^>]*\ssrc=["\']([^"\']+)["\']/i', $location->getEmbedHtml(), $m)) {
            $src = html_entity_decode($m[1]);
            if ($this->isAllowedUrl($src)) {
                return $src;
            }
        }

        $url = $location->getMapUrl();
        if ($url !== null && $url !== '' && $this->isAllowedUrl($url)) {
            return $url;
        }

        return null;
    }
}
