<?php

namespace App\Tests\Integration;

use App\Audit\AuditEvents;
use App\Audit\AuditExporter;
use App\Audit\AuditLogger;
use App\Audit\CertificateAuthority;
use App\Entity\AuditExport;
use App\Tests\DatabaseTestCase;

final class AuditExportTest extends DatabaseTestCase
{
    public function testLoggerPersistsEventsSynchronouslyUnderTest(): void
    {
        /** @var AuditLogger $logger */
        $logger = static::getContainer()->get(AuditLogger::class);

        $logger->system(AuditEvents::SECURITY, AuditEvents::CREATE, [
            'resourceType' => 'Thing',
            'resourceId' => 7,
            'details' => ['k' => 'v'],
        ]);

        $events = $this->em->getRepository(\App\Entity\AuditEvent::class)->findAll();
        self::assertCount(1, $events);
        self::assertSame('system', $events[0]->getActorType());
        self::assertSame(['k' => 'v'], $events[0]->getDetails());
    }

    public function testCertificateIsGeneratedAndSignatureVerifies(): void
    {
        /** @var CertificateAuthority $ca */
        $ca = static::getContainer()->get(CertificateAuthority::class);

        $certificate = $ca->ensureCertificate();
        self::assertNotEmpty($certificate->getFingerprint());
        // Idempotent: a second call returns the same certificate.
        self::assertSame($certificate->getId(), $ca->ensureCertificate()->getId());

        $signature = $ca->sign('payload');
        $publicKey = openssl_pkey_get_public($ca->publicCertificatePem());
        self::assertSame(1, openssl_verify('payload', base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256));
    }

    public function testExportProducesSignedVerifiablePackage(): void
    {
        /** @var AuditLogger $logger */
        $logger = static::getContainer()->get(AuditLogger::class);
        for ($i = 0; $i < 3; ++$i) {
            $logger->system(AuditEvents::CONFIGURATION, AuditEvents::UPDATE, ['resourceId' => $i]);
        }

        /** @var AuditExporter $exporter */
        $exporter = static::getContainer()->get(AuditExporter::class);
        $from = new \DateTimeImmutable('-1 day');
        $to = new \DateTimeImmutable('+1 day');
        $export = $exporter->export($from, $to, null, null);

        self::assertInstanceOf(AuditExport::class, $export);
        self::assertSame(3, $export->getEventCount());
        self::assertTrue($export->fileExists());
        self::assertFalse($export->isExpired());

        // Inspect the zip package.
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($export->getFilePath()));
        $json = $zip->getFromName('events.json');
        $pdf = $zip->getFromName('events.pdf');
        $manifest = $zip->getFromName('manifest.txt');
        $cert = $zip->getFromName('certificate.pem');
        $zip->close();

        self::assertNotFalse($json);
        self::assertSame($export->getSha256(), hash('sha256', $json));
        self::assertStringStartsWith('%PDF-1.4', (string) $pdf);
        self::assertStringContainsString('SHA-256', (string) $manifest);
        self::assertStringContainsString('BEGIN CERTIFICATE', (string) $cert);

        $decoded = json_decode((string) $json, true);
        self::assertArrayHasKey('export_manifest', $decoded);
        self::assertCount(3, $decoded['audit_events']);
        self::assertSame('SHA-256', $decoded['export_manifest']['hashing_algorithm']);

        @unlink($export->getFilePath());
    }
}
