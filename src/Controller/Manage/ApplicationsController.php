<?php

namespace App\Controller\Manage;

use App\Entity\User;
use App\Repository\UserVolunteerTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manager inbox of pending volunteer-type membership requests. Confirm/
 * deny actions reuse the per-type member routes (which enforce access).
 */
#[Route('/manage/applications')]
#[IsGranted('ROLE_USER')]
final class ApplicationsController extends AbstractController
{
    public function __construct(private readonly UserVolunteerTypeRepository $memberships)
    {
    }

    #[Route('', name: 'app_manage_applications', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Admins see every pending request; supporters see only their types.
        if ($this->isGranted('admin_volunteer_types')) {
            $pending = $this->memberships->findPending();
        } else {
            $supported = $this->memberships->findSupportedTypes($user);
            if ($supported === []) {
                throw $this->createAccessDeniedException('You do not manage any volunteer types.');
            }
            $pending = $this->memberships->findPending($supported);
        }

        return $this->render('manage/applications/index.html.twig', [
            'pending' => $pending,
        ]);
    }
}
