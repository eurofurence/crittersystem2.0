<?php

namespace App\Controller;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Repository\InviteTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accepts an account invitation: validates the one-time token, signs the user in
 * and sends them into the onboarding wizard (which ends by setting a password).
 * Expired or unknown tokens are rejected.
 */
final class InviteController extends AbstractController
{
    public function __construct(
        private readonly InviteTokenRepository $invites,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/invite/{token}', name: 'app_invite_accept', methods: ['GET'])]
    public function accept(string $token, Security $security): Response
    {
        $invite = $this->invites->findOneByToken($token);
        if ($invite === null || $invite->isExpired()) {
            return $this->render('invite/invalid.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        $user = $invite->getUser();
        // Mark the account as used so the stale-invite cleanup leaves it alone.
        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->remove($invite);
        $this->em->flush();

        $security->login($user);
        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, ['details' => ['invite' => 'accepted']]);

        return $this->redirectToRoute('app_onboarding');
    }
}
