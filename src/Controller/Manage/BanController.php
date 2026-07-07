<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\BannedIdentity;
use App\Repository\BannedIdentityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin review of ban appeals. A ban can only be lifted once the user has
 * submitted an appeal — admins cannot lift bans unprompted.
 */
#[Route('/manage/bans')]
#[IsGranted('user:delete')]
final class BanController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BannedIdentityRepository $bans,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_manage_ban_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/ban/index.html.twig', ['appeals' => $this->bans->findWithAppeals()]);
    }

    #[Route('/{id}/lift', name: 'app_manage_ban_lift', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function lift(Request $request, BannedIdentity $ban): Response
    {
        if (!$this->isCsrfTokenValid('lift'.$ban->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_ban_index');
        }

        if (!$ban->hasAppeal()) {
            $this->addFlash('danger', 'A ban can only be lifted after the user submits an appeal.');

            return $this->redirectToRoute('app_manage_ban_index');
        }

        $id = $ban->getId();
        $this->em->remove($ban);
        $this->em->flush();
        $this->audit->log(AuditEvents::GDPR, AuditEvents::DELETE, [
            'resourceType' => 'BannedIdentity',
            'resourceId' => $id,
            'details' => ['action' => 'ban_lifted'],
        ]);
        $this->addFlash('success', 'Ban lifted.');

        return $this->redirectToRoute('app_manage_ban_index');
    }
}
