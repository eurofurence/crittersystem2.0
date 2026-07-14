<?php

namespace App\EventSubscriber;

use App\Service\EmbedSanitizer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds a narrow Content-Security-Policy that only constrains frame-src to the
 * configured location-embed allowlist. It deliberately sets no
 * other directive, so scripts/styles are unaffected and the global CSP is not
 * weakened to support embedded maps.
 */
final class ContentSecurityPolicySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly EmbedSanitizer $embeds)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if ($response->headers->has('Content-Security-Policy')) {
            return;
        }

        $sources = array_merge(["'self'"], array_map(static fn ($h) => 'https://'.$h, $this->embeds->allowedHosts()));
        $response->headers->set('Content-Security-Policy', 'frame-src '.implode(' ', $sources));
    }
}
