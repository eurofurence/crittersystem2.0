<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CertificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * In-person check-in via QR. The route sits under `^/` (catch-all =
 * ROLE_USER), so anonymous scanners are sent to /login first and bounced back
 * here after login. The scanning user's pending application for the
 * certification is then approved.
 */
#[Route('/certification-scan')]
#[IsGranted('ROLE_USER')]
final class CertificationScanController extends AbstractController
{
    public function __construct(private readonly CertificationService $service)
    {
    }

    #[Route('/verify/{token}', name: 'app_certification_scan_verify', methods: ['GET'], requirements: ['token' => '[0-9a-f]{64}'])]
    public function verify(string $token): Response
    {
        $certToken = $this->service->findActiveToken($token);
        if ($certToken === null) {
            return $this->render('certification/scan_error.html.twig', [
                'message' => 'This QR code is invalid or has expired — ask the operator to refresh it.',
            ], new Response('', Response::HTTP_NOT_FOUND));
        }

        /** @var User $user */
        $user = $this->getUser();
        $result = $this->service->approveByQr($user, $certToken->getCertification());

        if (isset($result['error'])) {
            return $this->render('certification/scan_error.html.twig', [
                'certification' => $certToken->getCertification(),
                'message' => $result['error'],
            ]);
        }

        return $this->render('certification/scan_success.html.twig', [
            'certification' => $certToken->getCertification(),
            'record' => $result['record'],
        ]);
    }
}
