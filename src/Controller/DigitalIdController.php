<?php

namespace App\Controller;

use App\Entity\DigitalIdToken;
use App\Entity\User;
use App\Service\DigitalIdService;
use App\Service\QrCodeGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The user's digital ID card (a rotating QR pointing at a public verify page)
 * plus the public scan endpoint.
 */
#[Route('/digital-id')]
final class DigitalIdController extends AbstractController
{
    public function __construct(
        private readonly DigitalIdService $service,
        private readonly QrCodeGenerator $qr,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[IsGranted('ROLE_USER')]
    #[Route('', name: 'app_digital_id', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $token = $this->service->getOrCreateActive($user);

        $verifyUrl = $this->urls->generate('app_digital_id_verify', ['token' => $token->getToken()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('digital_id/index.html.twig', [
            'token' => $token,
            'verifyUrl' => $verifyUrl,
            'qrDataUri' => $this->qr->dataUri($verifyUrl, 320, 12),
            'ttlSeconds' => DigitalIdToken::DEFAULT_TTL_SECONDS,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route('/refresh', name: 'app_digital_id_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('digital-id-refresh', (string) $request->request->get('_token'))) {
            $this->service->refresh($user);
            $this->addFlash('success', 'A fresh QR has been issued.');
        }

        return $this->redirectToRoute('app_digital_id');
    }

    /** PUBLIC — scanned by anyone, no login required. */
    #[Route('/verify/{token}', name: 'app_digital_id_verify', methods: ['GET'], requirements: ['token' => '[0-9a-f]{64}'])]
    public function verify(string $token): Response
    {
        $record = $this->service->findActive($token);
        if ($record === null) {
            return $this->render('digital_id/verify_error.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
        }

        return $this->render('digital_id/verify.html.twig', ['user' => $record->getUser()]);
    }
}
