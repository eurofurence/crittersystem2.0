<?php

namespace App\Controller;

use App\Service\Install\MigrationInspector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness/readiness probe for container orchestrators (Docker healthcheck,
 * k8s readiness/liveness). Always public and never gated by the installation
 * listener, so the platform can scrape it during a migration.
 *
 *  - 200: database reachable and schema up to date — ready to serve traffic.
 *  - 503: database unreachable or a migration is still pending — not ready.
 */
final class HealthController extends AbstractController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(MigrationInspector $inspector): JsonResponse
    {
        $reachable = $inspector->isDatabaseReachable();
        $pending = $reachable ? $inspector->pendingMigrationCount() : null;
        $ready = $reachable && $pending === 0;

        return new JsonResponse([
            'status' => $ready ? 'ok' : 'unavailable',
            'database' => $reachable ? 'up' : 'down',
            'migrationsPending' => $pending,
        ], $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
