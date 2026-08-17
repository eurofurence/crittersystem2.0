<?php

namespace App\EventListener;

use App\Service\Install\InstallStateStore;
use App\Service\Install\MigrationInspector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Seals the application behind a maintenance page whenever the database needs a
 * migration (or is unreachable), so users never hit a half-migrated schema.
 *
 * Only the install wizard, the health probe and static assets stay reachable.
 * `/api/*` receives a machine-readable 503 JSON body; everything else gets the
 * Tabler maintenance page.
 *
 * Steady-state cost is a single filesystem read: {@see InstallStateStore} caches
 * a "ready" flag keyed by the latest shipped migration version, so the database
 * is only queried right after boot or right after a deploy that adds migrations.
 */
final class InstallationGateListener implements EventSubscriberInterface
{
    /** Path prefixes that must stay reachable even while the gate is closed. */
    private const ALLOWED_PREFIXES = [
        '/admin/install',
        '/health',
        '/assets',
        '/build',
        '/_wdt',
        '/_profiler',
        '/_error',
    ];

    public function __construct(
        private readonly MigrationInspector $inspector,
        private readonly InstallStateStore $state,
        private readonly Environment $twig,
        private readonly string $environment,
    ) {
    }

    /**
     * Priority 256 runs before the firewall, so the gate also seals the login page while a
     * migration is pending.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 256]];
    }

    /** The functional test database is provisioned out-of-band, so the test environment is never gated. */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->environment === 'test') {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $latest = $this->inspector->latestAvailableVersion();
        if ($this->state->isReadyFor($latest)) {
            return;
        }

        if (!$this->inspector->isMigrationNeeded()) {
            $this->state->markReady($latest);

            return;
        }

        $event->setResponse($this->buildResponse($event));
    }

    private function buildResponse(RequestEvent $event): Response
    {
        $path = $event->getRequest()->getPathInfo();

        if (str_starts_with($path, '/api')) {
            return new JsonResponse(
                [
                    'status' => 'maintenance',
                    'message' => 'The service is temporarily unavailable while a database migration is pending.',
                ],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '30'],
            );
        }

        $html = $this->twig->render('maintenance.html.twig');

        return new Response($html, Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '30']);
    }
}
