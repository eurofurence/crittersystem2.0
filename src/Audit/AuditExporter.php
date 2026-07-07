<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\AuditEvent;
use App\Entity\AuditExport;
use App\Entity\User;
use App\Repository\AuditEventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the immutable legal export package: a JSON forensic file (the
 * authoritative artifact), a presentable PDF, a manifest listing the filename,
 * timestamp, requester, SHA-256 hash and the signing public certificate, plus a
 * detached signature. Everything is zipped and recorded for time-limited
 * download. The act of exporting is itself written to the audit log by the
 * caller (chain of custody).
 */
final class AuditExporter
{
    public function __construct(
        private readonly AuditEventRepository $events,
        private readonly CertificateAuthority $certificateAuthority,
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
    }

    public function export(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?User $focusUser,
        ?User $requestedBy,
        ?string $legalHoldReference = null,
    ): AuditExport {
        $uuid = $this->uuid4();
        $dir = $this->projectDir.'/var/audit-exports';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create the audit export directory.');
        }

        $eventsArray = [];
        foreach ($this->events->streamForExport($from, $to, $focusUser?->getId()) as $event) {
            $eventsArray[] = $this->eventToArray($event);
            $this->em->detach($event);
        }

        $now = new \DateTimeImmutable();
        $manifest = [
            'export_timestamp' => $this->iso($now),
            'export_by_user_id' => $requestedBy?->getId(),
            'export_by_username' => $requestedBy?->getUserIdentifier() ?? 'system',
            'scope_start_time' => $this->iso($from),
            'scope_end_time' => $this->iso($to),
            'focus_user_id' => $focusUser?->getId(),
            'system_identifier' => gethostname() ?: 'critter-app',
            'hashing_algorithm' => 'SHA-256',
            'legal_hold_reference' => $legalHoldReference,
        ];

        $json = json_encode(
            ['export_manifest' => $manifest, 'audit_events' => $eventsArray],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
        if ($json === false) {
            throw new \RuntimeException('Failed to encode the audit export.');
        }

        $sha256 = hash('sha256', $json);
        $signature = $this->certificateAuthority->sign($json);
        $certificatePem = $this->certificateAuthority->publicCertificatePem();
        $pdf = $this->renderPdf($manifest, $eventsArray);
        $manifestTxt = $this->manifestText($manifest, $sha256, $signature, $certificatePem, \count($eventsArray));

        $zipPath = $dir.'/'.$uuid.'.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create the export archive.');
        }
        $zip->addFromString('events.json', $json);
        $zip->addFromString('events.pdf', $pdf);
        $zip->addFromString('manifest.txt', $manifestTxt);
        $zip->addFromString('certificate.pem', $certificatePem);
        $zip->close();

        $export = new AuditExport(
            $uuid,
            $requestedBy?->getId(),
            $requestedBy?->getUserIdentifier() ?? 'system',
            $from,
            $to,
            $focusUser?->getId(),
            $sha256,
            $zipPath,
            \count($eventsArray),
        );
        $this->em->persist($export);
        $this->em->flush();

        return $export;
    }

    /** @return array<string, mixed> */
    private function eventToArray(AuditEvent $e): array
    {
        return [
            'event_id' => $e->getEventId(),
            'timestamp' => $this->iso($e->getOccurredAt()),
            'event_type' => $e->getEventType(),
            'action' => $e->getAction(),
            'actor' => [
                'type' => $e->getActorType(),
                'user_id' => $e->getActorUserId(),
                'sso_user_id' => $e->getActorSsoId(),
                'username' => $e->getActorUsername(),
                'role' => $e->getActorRole(),
                'source_ip' => $e->getActorIp(),
                'user_agent' => $e->getActorUserAgent(),
            ],
            'resource' => [
                'type' => $e->getResourceType(),
                'id' => $e->getResourceId(),
                'owner_id' => $e->getResourceOwnerId(),
                'details' => $e->getResource(),
            ],
            'context' => [
                'session_id' => $e->getSessionId(),
                'request_url' => $e->getRequestUrl(),
                'mfa_verified' => $e->isMfaVerified(),
            ],
            'outcome' => [
                'status' => $e->getOutcome(),
                'http_status_code' => $e->getHttpStatus(),
                'error_message' => $e->getErrorMessage(),
            ],
            'details' => $e->getDetails(),
        ];
    }

    /**
     * @param array<string, mixed>        $manifest
     * @param array<int, array<string, mixed>> $events
     */
    private function renderPdf(array $manifest, array $events): string
    {
        $pdf = new PdfDocument();
        $pdf->addLine('CRITTER — AUDIT LOG EXPORT');
        $pdf->addLine(str_repeat('=', 100));
        $pdf->addLine('Exported at:      '.$manifest['export_timestamp']);
        $pdf->addLine('Exported by:      '.$manifest['export_by_username'].' (id '.($manifest['export_by_user_id'] ?? 'n/a').')');
        $pdf->addLine('Scope:            '.$manifest['scope_start_time'].'  ->  '.$manifest['scope_end_time']);
        $pdf->addLine('Focus user:       '.($manifest['focus_user_id'] ?? 'all'));
        $pdf->addLine('System:           '.$manifest['system_identifier']);
        $pdf->addLine('Events:           '.\count($events));
        $pdf->addLine('Legal hold ref:   '.($manifest['legal_hold_reference'] ?? '—'));
        $pdf->addLine(str_repeat('=', 100));
        $pdf->addLine('');

        foreach ($events as $e) {
            $actor = $e['actor'];
            $pdf->addLine($e['timestamp'].'  ['.$e['event_type'].'/'.$e['action'].']  '.$e['outcome']['status']);
            $pdf->addLine('   actor: '.$actor['type'].' '.($actor['username'] ?? '—').' (id '.($actor['user_id'] ?? '—').', ip '.($actor['source_ip'] ?? '—').')');
            if ($e['resource']['type'] !== null) {
                $pdf->addLine('   resource: '.$e['resource']['type'].' '.($e['resource']['id'] ?? ''));
            }
            if ($e['outcome']['error_message'] !== null) {
                $pdf->addLine('   error: '.$e['outcome']['error_message']);
            }
            $pdf->addLine('');
        }

        return $pdf->render();
    }

    /** @param array<string, mixed> $manifest */
    private function manifestText(array $manifest, string $sha256, string $signature, string $certificatePem, int $eventCount): string
    {
        return implode("\n", [
            'CRITTER AUDIT EXPORT — MANIFEST',
            '================================',
            'File:                 events.json',
            'Export timestamp:     '.$manifest['export_timestamp'],
            'Requested by:         '.$manifest['export_by_username'].' (id '.($manifest['export_by_user_id'] ?? 'n/a').')',
            'Scope start:          '.$manifest['scope_start_time'],
            'Scope end:            '.$manifest['scope_end_time'],
            'Focus user id:        '.($manifest['focus_user_id'] ?? 'all'),
            'Event count:          '.$eventCount,
            'Hashing algorithm:    SHA-256',
            'SHA-256 (events.json):'."\n".$sha256,
            '',
            'Detached signature (RSA-SHA256, base64):',
            $signature,
            '',
            'Signing certificate (PEM):',
            trim($certificatePem),
            '',
        ]);
    }

    private function iso(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
