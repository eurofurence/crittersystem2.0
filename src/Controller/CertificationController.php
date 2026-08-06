<?php

namespace App\Controller;

use App\Entity\Certification;
use App\Entity\User;
use App\Repository\CertificationRepository;
use App\Repository\UserCertificationRepository;
use App\Service\CertificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Volunteer-facing certifications: see active ones, your status per cert, and
 * apply or self-confirm. Approval-by-QR lives in {@see CertificationScanController}.
 */
#[Route('/certifications')]
#[IsGranted('ROLE_USER')]
final class CertificationController extends AbstractController
{
    public function __construct(
        private readonly CertificationRepository $certifications,
        private readonly UserCertificationRepository $userCerts,
        private readonly CertificationService $service,
    ) {
    }

    #[Route('', name: 'app_certifications_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $canSeeStaffOnly = $this->isGranted('ROLE_STAFF') || $this->isGranted('global:admin');

        $rows = [];
        foreach ($this->certifications->findAllOrdered() as $cert) {
            if (!$cert->isActive()) {
                continue;
            }
            if ($cert->isStaffOnly() && !$canSeeStaffOnly) {
                continue;
            }
            $rows[] = [
                'cert' => $cert,
                'record' => $this->userCerts->findOneByUserAndCertification($user, $cert),
            ];
        }

        return $this->render('certification/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/{id}', name: 'app_certifications_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        // Same visibility rule as the list: a staff-only certification is not
        // shown to volunteers who cannot see staff content.
        if ($certification->isStaffOnly() && !$this->isGranted('ROLE_STAFF') && !$this->isGranted('global:admin')) {
            throw $this->createNotFoundException();
        }

        return $this->render('certification/show.html.twig', ['cert' => $certification]);
    }

    #[Route('/{id}/apply', name: 'app_certifications_apply', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function apply(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('cert-apply'.$certification->getId(), (string) $request->request->get('_token'))) {
            $record = $this->service->applyFor($user, $certification);
            $record !== null
                ? $this->addFlash('success', new TranslatableMessage('certification.flash.applied', ['%name%' => $certification->getTitle()]))
                : $this->addFlash('warning', new TranslatableMessage('certification.flash.already_have'));
        }

        return $this->redirectToRoute('app_certifications_index');
    }

    /**
     * Withdraw one's own application, while nobody has decided on it yet.
     *
     * Only a pending record: once a manager has decided, taking the decision off the record is not
     * the volunteer's to do.
     */
    #[Route('/{id}/withdraw', name: 'app_certifications_withdraw', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function withdraw(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('cert-withdraw'.$certification->getId(), (string) $request->request->get('_token'))) {
            $record = $this->userCerts->findOneByUserAndCertification($user, $certification);
            if ($record !== null && $record->isPending()) {
                $this->service->withdraw($record, $user);
                $this->addFlash('success', new TranslatableMessage('certification.flash.withdrawn', ['%name%' => $certification->getTitle()]));
            } else {
                $this->addFlash('warning', new TranslatableMessage('certification.flash.nothing_to_withdraw'));
            }
        }

        return $this->redirectToRoute('app_certifications_index');
    }

    /**
     * The volunteer's own certifications as a file, for handing on to whoever asks them to prove it.
     *
     * Their own records only - the export is built from the signed-in user, never from a parameter.
     */
    #[Route('/export.csv', name: 'app_certifications_export', methods: ['GET'])]
    public function export(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $handle = fopen('php://temp', 'r+');
        // Escape given explicitly: PHP 8.4 deprecates leaving it to a default that is going to
        // change, and a backslash in a note must not silently alter the next field.
        fputcsv($handle, ['Certification', 'Status', 'Certified', 'Expires', 'Notes'], ',', '"', '');
        foreach ($this->userCerts->findByUser($user) as $record) {
            fputcsv($handle, [
                $record->getCertification()->getTitle(),
                $record->effectiveStatus(),
                $record->getDateCertified()?->format('Y-m-d') ?? '',
                $record->getDateExpires()?->format('Y-m-d') ?? '',
                $record->getNotes() ?? '',
            ], ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="my-certifications.csv"',
        ]);
    }

    #[Route('/{id}/self-confirm', name: 'app_certifications_self_confirm', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function selfConfirm(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('cert-self'.$certification->getId(), (string) $request->request->get('_token'))) {
            $record = $this->service->selfConfirm($user, $certification);
            $record !== null
                ? $this->addFlash('success', new TranslatableMessage('certification.flash.self_confirmed', ['%name%' => $certification->getTitle()]))
                : $this->addFlash('danger', new TranslatableMessage('certification.flash.cannot_self_confirm'));
        }

        return $this->redirectToRoute('app_certifications_index');
    }
}
