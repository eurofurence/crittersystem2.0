<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditExporter;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Repository\AuditEventRepository;
use App\Repository\AuditExportRepository;
use App\Repository\UserRepository;
use App\Storage\ExportStorage;
use App\TwoFactor\StepUpGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Audit log viewer and legal export. Access is admin-only; the underlying
 * permissions (audit:view / audit:export) are flagged as requiring step-up
 * authentication, which the step-up guard enforces once 2FA is in place.
 */
#[Route('/manage/audit')]
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
        private readonly ExportStorage $storage,
    ) {
    }

    #[Route('', name: 'app_manage_audit', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $type = $request->query->get('type');
        $actorUserId = $request->query->get('user');

        return $this->render('admin/audit/index.html.twig', [
            'events' => $this->events->findRecent($type, $actorUserId !== null && $actorUserId !== '' ? (int) $actorUserId : null),
            'exports' => $this->exports->findRecent(),
            // Which archives are actually still in storage; the entity cannot answer that any more.
            'storedKeys' => $this->storage->keysUnder(AuditExporter::KEY_PREFIX),
            'eventTypes' => $this->eventTypeChoices(),
            'selectedType' => $type,
        ]);
    }

    /**
     * Type-ahead source for the export form's focus-user picker. Behind `audit:view` like the rest of
     * this controller, and it answers with public uuids - the export form resolves a user by uuid, so
     * an admin never has to know (or be shown) an internal id.
     */
    #[Route('/user-search', name: 'app_manage_audit_user_search', methods: ['GET'])]
    public function userSearch(Request $request, \App\Service\UserSearchResultFormatter $formatter): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        return new \Symfony\Component\HttpFoundation\JsonResponse(
            $formatter->results($q === '' ? [] : $this->users->searchByName($q)),
        );
    }

    #[Route('/export', name: 'app_manage_audit_export', methods: ['POST'])]
    #[IsGranted('audit:export')]
    public function export(Request $request): Response
    {
        if ($stepUp = $this->stepUp->guard($request)) {
            return $stepUp;
        }
        if (!$this->isCsrfTokenValid('audit_export', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('admin.audit.flash.invalid_token'));

            return $this->redirectToRoute('app_manage_audit');
        }

        $from = $this->parseDate((string) $request->request->get('from'), false);
        $to = $this->parseDate((string) $request->request->get('to'), true);
        if ($from === null || $to === null || $from > $to) {
            $this->addFlash('danger', new TranslatableMessage('admin.audit.flash.invalid_dates'));

            return $this->redirectToRoute('app_manage_audit');
        }

        $focusUser = null;
        $focusUuid = (string) $request->request->get('focus_user');
        if ($focusUuid !== '') {
            $focusUser = $this->users->findOneByUuid($focusUuid);
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

        $this->addFlash('success', new TranslatableMessage('admin.audit.flash.export_ready', ['%count%' => $export->getEventCount(), '%days%' => \App\Entity\AuditExport::RETENTION_DAYS]));

        return $this->redirectToRoute('app_manage_audit');
    }

    #[Route('/download/{uuid}', name: 'app_manage_audit_download', methods: ['GET'])]
    #[IsGranted('audit:export')]
    public function download(string $uuid): Response
    {
        $export = $this->exports->findOneByUuid($uuid);
        if ($export === null) {
            throw $this->createNotFoundException();
        }

        if ($export->isExpired() || !$this->storage->exists($export->getStorageKey())) {
            $this->audit->log(AuditEvents::DATA_EXPORT, AuditEvents::DOWNLOAD, [
                'outcome' => AuditEvents::FAILURE,
                'resourceType' => 'AuditExport',
                'resourceId' => $uuid,
                'errorMessage' => 'Export expired or file missing.',
            ]);
            $this->addFlash('danger', new TranslatableMessage('admin.audit.flash.export_expired'));

            return $this->redirectToRoute('app_manage_audit');
        }

        $export->markDownloaded(new \DateTimeImmutable());
        $this->em->flush();

        $this->audit->log(AuditEvents::DATA_EXPORT, AuditEvents::DOWNLOAD, [
            'resourceType' => 'AuditExport',
            'resourceId' => $uuid,
        ]);

        return $this->storage->download($export->getStorageKey(), 'audit-export-'.$uuid.'.zip');
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
