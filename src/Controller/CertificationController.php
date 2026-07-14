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

    #[Route('/{id}/apply', name: 'app_certifications_apply', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function apply(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('cert-apply'.$certification->getId(), (string) $request->request->get('_token'))) {
            $record = $this->service->applyFor($user, $certification);
            $record !== null
                ? $this->addFlash('success', \sprintf('Application submitted for "%s". Scan the certification QR at the event to complete it.', $certification->getTitle()))
                : $this->addFlash('warning', 'You already have a record for this certification.');
        }

        return $this->redirectToRoute('app_certifications_index');
    }

    #[Route('/{id}/self-confirm', name: 'app_certifications_self_confirm', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function selfConfirm(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('cert-self'.$certification->getId(), (string) $request->request->get('_token'))) {
            $record = $this->service->selfConfirm($user, $certification);
            $record !== null
                ? $this->addFlash('success', \sprintf('Self-confirmed "%s".', $certification->getTitle()))
                : $this->addFlash('danger', 'You cannot self-confirm this certification right now.');
        }

        return $this->redirectToRoute('app_certifications_index');
    }
}
