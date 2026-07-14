<?php

namespace App\Controller\Manage;

use App\Entity\Certification;
use App\Entity\CertificationToken;
use App\Form\CertificationType;
use App\Repository\CertificationRepository;
use App\Service\CertificationService;
use App\Service\QrCodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/certifications')]
#[IsGranted('global:admin')]
final class CertificationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CertificationRepository $certifications,
        private readonly CertificationService $service,
        private readonly QrCodeGenerator $qr,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/{id}/qr', name: 'app_manage_certification_qr', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function qr(#[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        $token = $this->service->getOrCreateToken($certification);
        $verifyUrl = $this->urls->generate('app_certification_scan_verify', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('manage/certification/qr.html.twig', [
            'certification' => $certification,
            'token' => $token,
            'verifyUrl' => $verifyUrl,
            'qrDataUri' => $this->qr->dataUri($verifyUrl, 360, 12),
            'ttlSeconds' => CertificationToken::DEFAULT_TTL_SECONDS,
        ]);
    }

    #[Route('/{id}/qr/refresh', name: 'app_manage_certification_qr_refresh', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function refreshQr(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        if ($this->isCsrfTokenValid('cert-qr-refresh'.$certification->getId(), (string) $request->request->get('_token'))) {
            $this->service->refreshToken($certification);
            $this->addFlash('success', 'A fresh QR has been issued.');
        }

        return $this->redirectToRoute('app_manage_certification_qr', ['id' => $certification->getUuid()]);
    }

    #[Route('', name: 'app_manage_certification_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/certification/index.html.twig', [
            'certifications' => $this->certifications->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_manage_certification_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $certification = new Certification('');
        $form = $this->createForm(CertificationType::class, $certification);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($certification);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Certification "%s" created.', $certification->getTitle()));

            return $this->redirectToRoute('app_manage_certification_index');
        }

        return $this->render('manage/certification/form.html.twig', [
            'form' => $form,
            'heading' => 'New certification',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_certification_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        $form = $this->createForm(CertificationType::class, $certification);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', \sprintf('Certification "%s" updated.', $certification->getTitle()));

            return $this->redirectToRoute('app_manage_certification_index');
        }

        return $this->render('manage/certification/form.html.twig', [
            'form' => $form,
            'heading' => 'Edit certification',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_certification_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        if ($this->isCsrfTokenValid('delete'.$certification->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($certification);
            $this->em->flush();
            $this->addFlash('success', 'Certification deleted.');
        }

        return $this->redirectToRoute('app_manage_certification_index');
    }
}
