<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Certification;
use App\Service\CertificationCompliance;
use App\Service\CertificationService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reports over certifications: one certification's holders, and who across the event is short of a
 * certification their role requires.
 *
 * Both leave the server as a list of named people and what they are qualified for, so both are
 * audited as data exports. Usernames only - the holder lists elsewhere show the same and nothing
 * more, and a spreadsheet travels further than a page does.
 */
#[Route('/manage/certifications')]
#[IsGranted('certification:manage')]
final class CertificationReportController extends AbstractController
{
    public function __construct(
        private readonly CertificationService $service,
        private readonly CertificationCompliance $compliance,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/compliance', name: 'app_manage_certification_compliance', methods: ['GET'])]
    public function compliance(): Response
    {
        $report = $this->compliance->report();

        return $this->render('manage/certification/compliance.html.twig', [
            'report' => $report,
            'totals' => $this->compliance->totals($report),
        ]);
    }

    #[Route('/compliance.csv', name: 'app_manage_certification_compliance_export', methods: ['GET'])]
    public function complianceCsv(): Response
    {
        $report = $this->compliance->report();
        $rows = $this->compliance->rows($report);

        $this->audit->log(AuditEvents::DATA_EXPORT, AuditEvents::EXPORT, [
            'resourceType' => 'certification',
            'details' => ['report' => 'compliance', 'rows' => \count($rows)],
        ]);

        return $this->csv(
            ['Volunteer type', 'Volunteer', 'Missing'],
            $rows,
            'certification-compliance.csv',
        );
    }

    #[Route('/{id}/export.csv', name: 'app_manage_certification_export', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function holders(#[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        $rows = [];
        foreach ($this->service->holdersByStatus($certification) as $status => $records) {
            foreach ($records as $record) {
                $rows[] = [
                    $record->getUser()->getName(),
                    $status,
                    $record->getDateCertified()?->format('Y-m-d') ?? '',
                    $record->getDateExpires()?->format('Y-m-d') ?? '',
                    $record->getCertifiedBy()?->getName() ?? '',
                    $record->getDecisionReason() ?? '',
                    $record->getNotes() ?? '',
                ];
            }
        }

        $this->audit->log(AuditEvents::DATA_EXPORT, AuditEvents::EXPORT, [
            'resourceType' => 'certification',
            'resourceId' => (string) $certification->getUuid(),
            'details' => ['report' => 'holders', 'certification' => $certification->getTitle(), 'rows' => \count($rows)],
        ]);

        return $this->csv(
            ['Volunteer', 'Status', 'Certified', 'Expires', 'Decided by', 'Reason', 'Notes'],
            $rows,
            \sprintf('certification-%s.csv', $this->slug($certification->getTitle())),
        );
    }

    /**
     * @param list<string>            $header
     * @param list<array<int, string>> $rows
     */
    private function csv(array $header, array $rows, string $filename): Response
    {
        $handle = fopen('php://temp', 'r+');
        // The escape character is given explicitly: PHP 8.4 deprecates leaving it to a default that
        // is going to change, and a backslash in a note must not silently alter the next field.
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => \sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function slug(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? '', '-'));

        return $slug === '' ? 'certification' : $slug;
    }
}
