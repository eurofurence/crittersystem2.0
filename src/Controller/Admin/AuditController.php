<?php

namespace App\Controller\Admin;

use App\Audit\AuditEvents;
use App\Audit\AuditExporter;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Repository\AuditEventRepository;
use App\Repository\AuditExportRepository;
use App\Repository\UserRepository;
use App\TwoFactor\StepUpGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit log viewer and legal export. Access is admin-only; the underlying
 * permissions (audit:view / audit:export) are flagged as requiring step-up
 * authentication, which the step-up guard enforces once 2FA is in place.
 */
#[Route('/admin/audit')]
#[IsGranted('audit:view')]
final class AuditController extends AbstractController
{
    public function __construct(
        private readonly AuditEventRepository $events,
        private readonly AuditExportRepository $exports,
        private readonly AuditExporter $exporter,
        private readonly AuditLogger $audit,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly StepUpGuard $stepUp,
    ) {
    }

    #[Route('', name: 'app_admin_audit', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $type = $request->query->get('type');
        $actorUserId = $request->query->get('user');

        return $this->render('admin/audit/index.html.twig', [
            'events' => $this->events->findRecent($type, $actorUserId !== null && $actorUserId !== '' ? (int) $actorUserId : null),
            'exports' => $this->exports->findRecent(),
            'eventTypes' => $this->eventTypeChoices(),
            'selectedType' => $type,
        ]);
    }

    #[Route('/export', name: 'app_admin_audit_export', methods: ['POST'])]
    #[IsGranted('audit:export')]
    public function export(Request $request): Response
    {
        if ($stepUp = $this->stepUp->guard($request)) {
            return $stepUp;
        }
        if (!$this->isCsrfTokenValid('audit_export', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');

            return $this->redirectToRoute('app_admin_audit');
        }

        $from = $this->parseDate((string) $request->request->get('from'), false);
        $to = $this->parseDate((string) $request->request->get('to'), true);
        if ($from === null || $to === null || $from > $to) {
            $this->addFlash('danger', 'Provide a valid start and end date.');

            return $this->redirectToRoute('app_admin_audit');
        }

        $focusUser = null;
        $focusId = $request->request->get('focus_user');
        if ($focusId !== null && $focusId !== '') {
            $focusUser = $this->users->find((int) $focusId);
        }
        $legalHold = trim((string) $request->request->get('legal_hold')) ?: null;

        /** @var User $admin */
        $admin = $this->getUser();
        $export = $this->exporter->export($from, $to, $focusUser, $admin, $legalHold);

        // Chain of custody: the act of exporting is itself audited.
        $this->audit->log(AuditEvents::DATA_EXPORT, AuditEvents::EXPORT, [
            'resourceType' => 'AuditExport',
            'resourceId' => $export->getUuid(),
            'details' => [
                'scope_start' => $from->format(\DATE_ATOM),
                'scope_end' => $to->format(\DATE_ATOM),
                'focus_user_id' => $focusUser?->getId(),
                'event_count' => $export->getEventCount(),
                'sha256' => $export->getSha256(),
                'legal_hold_reference' => $legalHold,
            ],
        ]);

        $this->addFlash('success', \sprintf('Export ready (%d events). Download it within %d days.', $export->getEventCount(), \App\Entity\AuditExport::RETENTION_DAYS));

        return $this->redirectToRoute('app_admin_audit');
    }

    #[Route('/download/{uuid}', name: 'app_admin_audit_download', methods: ['GET'])]
    #[IsGranted('audit:export')]
    public function download(string $uuid): Response
    {
        $export = $this->exports->findOneByUuid($uuid);
        if ($export === null) {
            throw $this->createNotFoundException();
        }

        if ($export->isExpired() || !$export->fileExists()) {
            $this->audit->log(AuditEvents::DATA_EXPORT, AuditEvents::DOWNLOAD, [
                'outcome' => AuditEvents::FAILURE,
                'resourceType' => 'AuditExport',
                'resourceId' => $uuid,
                'errorMessage' => 'Export expired or file missing.',
            ]);
            $this->addFlash('danger', 'That export has expired and is no longer available.');

            return $this->redirectToRoute('app_admin_audit');
        }

        $export->markDownloaded(new \DateTimeImmutable());
        $this->em->flush();

        $this->audit->log(AuditEvents::DATA_EXPORT, AuditEvents::DOWNLOAD, [
            'resourceType' => 'AuditExport',
            'resourceId' => $uuid,
        ]);

        $response = new BinaryFileResponse($export->getFilePath());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'audit-export-'.$uuid.'.zip');

        return $response;
    }

    private function parseDate(string $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }

    /** @return array<string, string> */
    private function eventTypeChoices(): array
    {
        $types = [
            AuditEvents::AUTHENTICATION, AuditEvents::AUTHORIZATION, AuditEvents::ACCESS_CONTROL,
            AuditEvents::USER_MANAGEMENT, AuditEvents::DATA_ACCESS, AuditEvents::DATA_EXPORT,
            AuditEvents::CONFIGURATION, AuditEvents::SHIFT, AuditEvents::CONSENT, AuditEvents::GDPR,
            AuditEvents::SECURITY, AuditEvents::NOTIFICATION,
        ];

        return array_combine($types, $types);
    }
}
