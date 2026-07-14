<?php

namespace App\Tests\Integration;

use App\Audit\AuditEventHandler;
use App\Audit\AuditEvents;
use App\Audit\AuditRecord;
use App\Repository\AuditEventRepository;
use App\Tests\DatabaseTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the delivery path of the audit trail.
 *
 * Audit events are dispatched to the async Messenger transport and written to `audit_events` by a
 * worker. Without a running worker every audited action piles up in `messenger_messages` and no
 * audit row is ever written, while the application appears healthy. The test environment cannot
 * detect that on its own: `config/packages/messenger.yaml` re-routes AuditRecord to `sync` under
 * `when@test`, so PHP-level tests exercise a delivery path production never uses.
 *
 * These tests therefore assert on the deployment manifests as well as on PHP behaviour: every way
 * this application is deployed must start a Messenger worker.
 */
final class AuditDeliveryTest extends DatabaseTestCase
{
    private function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function testTheHandlerWritesAnAuditRow(): void
    {
        $handler = static::getContainer()->get(AuditEventHandler::class);
        $repo = static::getContainer()->get(AuditEventRepository::class);
        $before = $repo->countAll();

        $handler(new AuditRecord(
            eventId: 'evt_'.bin2hex(random_bytes(8)),
            occurredAt: new \DateTimeImmutable(),
            eventType: AuditEvents::SECURITY,
            action: AuditEvents::UPDATE,
            outcome: AuditEvents::SUCCESS,
            actorType: 'system',
            actorUserId: null,
            actorSsoId: null,
            actorUsername: null,
            actorRole: null,
            actorIp: null,
            actorUserAgent: null,
            resourceType: 'Test',
            resourceId: '1',
            resourceOwnerId: null,
            resource: [],
            details: [],
            sessionId: null,
            requestUrl: null,
            mfaVerified: false,
            httpStatus: null,
            errorMessage: null,
        ));

        self::assertSame($before + 1, $repo->countAll());
    }

    /**
     * AuditRecord must be routed to a transport that something actually consumes. An unrouted
     * message is handled inline and is therefore harmless; a message routed to a transport with no
     * worker is silently lost.
     */
    public function testAuditRecordsAreRoutedToTheAsyncTransport(): void
    {
        $config = Yaml::parseFile($this->projectDir().'/config/packages/messenger.yaml');
        $routing = $config['framework']['messenger']['routing'] ?? [];

        self::assertArrayHasKey(AuditRecord::class, $routing, 'AuditRecord must be explicitly routed.');
        self::assertSame('async', $routing[AuditRecord::class]);
    }

    /**
     * Every way this application is deployed must run a Messenger worker, otherwise the audit trail
     * (and GDPR exports, and all mail) is queued and never written.
     *
     * @return iterable<string, array{string}>
     */
    public static function deploymentManifests(): iterable
    {
        yield 'docker compose (prod)' => ['compose.prod.yaml'];
        yield 'docker compose (dev)' => ['compose.dev.yaml'];
        yield 'kubernetes' => ['deploy/k8s/app.yaml'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deploymentManifests')]
    public function testEveryDeploymentRunsAMessengerWorker(string $manifest): void
    {
        $path = $this->projectDir().'/'.$manifest;
        self::assertFileExists($path);

        self::assertStringContainsString(
            'messenger:consume',
            (string) file_get_contents($path),
            $manifest.' defines no Messenger worker. Without one, every audit event, GDPR export and '
                .'email is queued and NEVER written, while the application appears to work normally.',
        );
    }

    public function testTheDeploymentManualDocumentsTheWorker(): void
    {
        self::assertStringContainsString(
            'messenger:consume',
            (string) file_get_contents($this->projectDir().'/docs/deploy.md'),
            'deploy.md must tell operators to run the worker; its absence is what caused the audit trail to go unwritten.',
        );
    }
}
