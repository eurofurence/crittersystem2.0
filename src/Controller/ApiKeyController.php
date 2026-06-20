<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lets a user view and regenerate their personal API key (used for the JSON
 * API and feeds via Bearer / X-API-Key / ?key=).
 */
#[Route('/settings/api-key')]
#[IsGranted('ROLE_USER')]
final class ApiKeyController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'app_api_key', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('api_key/index.html.twig');
    }

    #[Route('/regenerate', name: 'app_api_key_regenerate', methods: ['POST'])]
    public function regenerate(Request $request): Response
    {
        if ($this->isCsrfTokenValid('api-key-regenerate', (string) $request->request->get('_token'))) {
            /** @var User $user */
            $user = $this->getUser();
            $user->setApiKey(bin2hex(random_bytes(16)));
            $this->em->flush();
            $this->addFlash('success', 'Your API key has been regenerated. Update any clients using the old key.');
        }

        return $this->redirectToRoute('app_api_key');
    }
}
