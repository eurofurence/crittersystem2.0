<?php

namespace App\Controller\Manage;

use App\Entity\Certification;
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
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/manage/certifications')]
#[IsGranted('certification:manage')]
final class CertificationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CertificationRepository $certifications,
        private readonly CertificationService $service,
        private readonly QrCodeGenerator $qr,
        private readonly UrlGeneratorInterface $urls,
        private readonly \App\Repository\UserCertificationRepository $records,
    ) {
    }

    /**
     * How long before expiry the displayed QR is replaced. It is also the life a token must still
     * have to be shown at all, so the card never declares a rotation moment that has already passed
     * and the region cannot fetch itself in a loop.
     */
    private const QR_ROTATION_MARGIN_SECONDS = 30;

    #[Route('/{id}/qr', name: 'app_manage_certification_qr', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function qr(#[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        return $this->render('manage/certification/qr.html.twig', $this->qrCardContext($certification));
    }

    #[Route('/{id}/qr/card', name: 'app_manage_certification_qr_card', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function qrCard(#[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        return $this->render('manage/certification/_qr_card.html.twig', $this->qrCardContext($certification));
    }

    /** @return array<string, mixed> */
    private function qrCardContext(Certification $certification): array
    {
        $token = $this->service->getOrCreateToken($certification, self::QR_ROTATION_MARGIN_SECONDS);
        $verifyUrl = $this->urls->generate('app_certification_scan_verify', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);

        return [
            'certification' => $certification,
            'token' => $token,
            'verifyUrl' => $verifyUrl,
            'qrDataUri' => $this->qr->dataUri($verifyUrl, 360, 12),
            'refreshAt' => $token->getExpiresAt()->modify(sprintf('-%d seconds', self::QR_ROTATION_MARGIN_SECONDS)),
        ];
    }

    #[Route('/{id}/qr/refresh', name: 'app_manage_certification_qr_refresh', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function refreshQr(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        if ($this->isCsrfTokenValid('cert-qr-refresh'.$certification->getId(), (string) $request->request->get('_token'))) {
            $this->service->refreshToken($certification);
            $this->addFlash('success', new TranslatableMessage('manage.certification.flash.qr_reissued'));
        }

        return $this->redirectToRoute('app_manage_certification_qr', ['id' => $certification->getUuid()]);
    }

    #[Route('', name: 'app_manage_certification_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/certification/index.html.twig', [
            'certifications' => $this->certifications->findAllOrdered(),
            'statistics' => $this->records->statistics(),
        ]);
    }

    /**
     * Who applied for this certification and who holds it, grouped by what each record counts as
     * today.
     *
     * Each section owns a `<status>_q` and a `<status>_page` parameter, and carries the other
     * sections' parameters through, so searching or paging one table leaves the rest of the page
     * where the viewer left it. Only the known parameters are carried, so an arbitrary query string
     * cannot be reflected back into the page's own links.
     *
     * Page numbers are cast rather than read with getInt(), which throws on a value it cannot
     * convert: a malformed or blank one in a hand-edited URL falls back to the first page rather
     * than answering 400.
     */
    #[Route('/{id}', name: 'app_manage_certification_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        $grouped = $this->service->holdersByStatus($certification);

        $carried = [];
        foreach (array_keys($grouped) as $status) {
            foreach ([$status.'_q', $status.'_page'] as $name) {
                $value = $request->query->get($name);
                if (\is_string($value) && $value !== '') {
                    $carried[$name] = $value;
                }
            }
        }

        $sections = [];
        $counts = [];
        foreach ($grouped as $status => $records) {
            $section = $this->service->paginateHolders(
                $records,
                (string) $request->query->get($status.'_q', ''),
                max(1, (int) $request->query->get($status.'_page', 1)),
            );

            $section['key'] = $status;
            $section['keep'] = array_diff_key($carried, [$status.'_page' => true]);
            $section['formKeep'] = array_diff_key($carried, [$status.'_page' => true, $status.'_q' => true]);

            $sections[$status] = $section;
            $counts[$status] = $section['totalAll'];
        }

        return $this->render('manage/certification/show.html.twig', [
            'certification' => $certification,
            'sections' => $sections,
            'counts' => $counts,
            'total' => array_sum($counts),
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
            $this->addFlash('success', new TranslatableMessage('manage.certification.flash.created', ['%name%' => $certification->getTitle()]));

            return $this->redirectToRoute('app_manage_certification_index');
        }

        return $this->render('manage/certification/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.certification.form.heading_new',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_certification_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        $form = $this->createForm(CertificationType::class, $certification);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.certification.flash.updated', ['%name%' => $certification->getTitle()]));

            return $this->redirectToRoute('app_manage_certification_index');
        }

        return $this->render('manage/certification/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.certification.form.heading_edit',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_certification_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Certification $certification): Response
    {
        if ($this->isCsrfTokenValid('delete'.$certification->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($certification);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.certification.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_certification_index');
    }
}
