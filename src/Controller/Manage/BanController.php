<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\BannedIdentity;
use App\Repository\BannedIdentityRepository;
use App\Service\NoShowBanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Ban administration. Lists all bans with their reason and whether
 * they were automatic or manual. Behavioural bans (linked to a live user) can
 * be lifted directly, which also resets the no-show counter; hashed GDPR bans
 * still require the user to have appealed first.
 */
#[Route('/manage/bans')]
#[IsGranted('user:delete')]
final class BanController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BannedIdentityRepository $bans,
        private readonly NoShowBanService $noShowBans,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_manage_ban_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/ban/index.html.twig', ['bans' => $this->bans->findRecentAll()]);
    }

    /**
     * A ban still linked to a live user is behavioural: lifting it lifts every ban record that user
     * holds and resets their no-show counter. A hashed GDPR ban has no user and may only be removed
     * once the person has appealed.
     */
    #[Route('/{id}/lift', name: 'app_manage_ban_lift', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function lift(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] BannedIdentity $ban): Response
    {
        if (!$this->isCsrfTokenValid('lift'.$ban->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_ban_index');
        }

        $user = $ban->getUser();
        if ($user !== null) {
            $this->noShowBans->liftAndReset($user, 'Lifted by administrator');
            $this->addFlash('success', new TranslatableMessage('manage.ban.flash.lifted_reset'));

            return $this->redirectToRoute('app_manage_ban_index');
        }

        if (!$ban->hasAppeal()) {
            $this->addFlash('danger', new TranslatableMessage('manage.ban.flash.gdpr_appeal_required'));

            return $this->redirectToRoute('app_manage_ban_index');
        }

        $id = $ban->getId();
        $this->em->remove($ban);
        $this->em->flush();
        $this->audit->log(AuditEvents::GDPR, AuditEvents::UNBAN, [
            'resourceType' => 'BannedIdentity',
            'resourceId' => $id,
        ]);
        $this->addFlash('success', new TranslatableMessage('manage.ban.flash.lifted'));

        return $this->redirectToRoute('app_manage_ban_index');
    }
}
