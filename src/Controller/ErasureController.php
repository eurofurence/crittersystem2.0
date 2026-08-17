<?php

namespace App\Controller;

use App\Gdpr\ErasureService;
use App\Repository\ErasureRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Confirms and performs irreversible account deletion from the emailed link.
 * GET shows a final confirmation; POST executes the erasure (so link prefetch
 * cannot delete an account).
 */
final class ErasureController extends AbstractController
{
    public function __construct(
        private readonly ErasureRequestRepository $requests,
        private readonly ErasureService $erasure,
    ) {
    }

    #[Route('/erase/{token}', name: 'app_erase_confirm', methods: ['GET'])]
    public function confirm(string $token): Response
    {
        $request = $this->requests->findOneByToken($token);
        if ($request === null || $request->isExpired()) {
            return $this->render('erase/invalid.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        return $this->render('erase/confirm.html.twig', ['token' => $token]);
    }

    /** The account no longer exists once the erasure has run, so the session goes with it. */
    #[Route('/erase/{token}', name: 'app_erase_execute', methods: ['POST'])]
    public function execute(string $token, Request $request): Response
    {
        $erasureRequest = $this->requests->findOneByToken($token);
        if ($erasureRequest === null || $erasureRequest->isExpired()) {
            return $this->render('erase/invalid.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        $this->erasure->execute($erasureRequest);

        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return $this->render('erase/done.html.twig');
    }
}
