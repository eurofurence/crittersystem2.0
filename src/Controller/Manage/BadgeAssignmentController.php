<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Repository\BadgeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Mass badge assignment: filter users, select several, then apply or remove a
 * badge for all of them at once.
 */
#[Route('/manage/badges/assign')]
#[IsGranted('badge:assign')]
final class BadgeAssignmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BadgeRepository $badges,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_manage_badge_assign', methods: ['GET', 'POST'])]
    public function assign(Request $request): Response
    {
        $badgeId = $request->query->get('badge') ?: $request->request->get('badge');
        $badge = $badgeId ? $this->badges->findOneByUuid((string) $badgeId) : null;
        $query = trim((string) ($request->query->get('q') ?? $request->request->get('q', '')));

        if ($request->isMethod('POST') && $badge !== null) {
            $action = $request->request->get('action');
            $userIds = array_map('intval', (array) $request->request->all('users'));
            $changed = 0;
            foreach ($userIds as $id) {
                $user = $this->users->find($id);
                if ($user === null) {
                    continue;
                }
                if ($action === 'add' && !$user->hasBadge($badge)) {
                    $user->addBadge($badge);
                    ++$changed;
                } elseif ($action === 'remove' && $user->hasBadge($badge)) {
                    $user->removeBadge($badge);
                    ++$changed;
                }
            }
            $this->em->flush();
            $this->audit->log(AuditEvents::USER_MANAGEMENT, $action === 'add' ? AuditEvents::GRANT : AuditEvents::REVOKE, [
                'resourceType' => 'Badge',
                'resourceId' => $badge->getId(),
                'details' => ['badge' => $badge->getSlug(), 'users' => $userIds, 'changed' => $changed],
            ]);
            $this->addFlash('success', new TranslatableMessage(
                $action === 'add' ? 'manage.badge.flash.assigned' : 'manage.badge.flash.removed',
                ['%name%' => $badge->getName(), '%count%' => $changed],
            ));

            return $this->redirectToRoute('app_manage_badge_assign', ['badge' => $badge->getUuid(), 'q' => $query]);
        }

        $results = $query !== '' ? $this->users->search($query, 50) : [];

        return $this->render('manage/badge/assign.html.twig', [
            'badges' => $this->badges->findAllOrdered(),
            'badge' => $badge,
            'query' => $query,
            'results' => $results,
        ]);
    }
}
