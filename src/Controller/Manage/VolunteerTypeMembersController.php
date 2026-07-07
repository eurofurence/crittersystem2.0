<?php

namespace App\Controller\Manage;

use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Repository\UserVolunteerTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Member management for a volunteer type. Open to holders of
 * admin_volunteer_types and to supporters of the type itself
 */
#[Route('/manage/volunteer-types/{id}/members', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class VolunteerTypeMembersController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserVolunteerTypeRepository $memberships,
    ) {
    }

    #[Route('', name: 'app_manage_vt_members', methods: ['GET'])]
    public function index(VolunteerType $type): Response
    {
        $this->assertCanManage($type);

        return $this->render('manage/volunteer_type/members.html.twig', [
            'type' => $type,
            'members' => $this->memberships->findByVolunteerType($type),
        ]);
    }

    #[Route('/{membershipId}/confirm', name: 'app_manage_vt_member_confirm', methods: ['POST'], requirements: ['membershipId' => '\d+'])]
    public function confirm(Request $request, VolunteerType $type, int $membershipId): Response
    {
        $this->assertCanManage($type);
        $membership = $this->resolveMembership($type, $membershipId);

        if ($membership !== null && $this->isCsrfTokenValid('confirm'.$membershipId, (string) $request->request->get('_token'))) {
            /** @var User $actor */
            $actor = $this->getUser();
            $membership->setConfirmedBy($actor);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Confirmed %s.', $membership->getUser()->getName()));
        }

        return $this->redirectToRoute('app_manage_vt_members', ['id' => $type->getId()]);
    }

    #[Route('/{membershipId}/supporter', name: 'app_manage_vt_member_supporter', methods: ['POST'], requirements: ['membershipId' => '\d+'])]
    public function toggleSupporter(Request $request, VolunteerType $type, int $membershipId): Response
    {
        $this->assertCanManage($type);
        $membership = $this->resolveMembership($type, $membershipId);

        if ($membership !== null && $this->isCsrfTokenValid('supporter'.$membershipId, (string) $request->request->get('_token'))) {
            // Promoting to supporter also confirms the membership.
            if (!$membership->isSupporter() && !$membership->isConfirmed()) {
                /** @var User $actor */
                $actor = $this->getUser();
                $membership->setConfirmedBy($actor);
            }
            $membership->setSupporter(!$membership->isSupporter());
            $this->em->flush();
            $this->addFlash('success', \sprintf('%s is %s a supporter.', $membership->getUser()->getName(), $membership->isSupporter() ? 'now' : 'no longer'));
        }

        return $this->redirectToRoute('app_manage_vt_members', ['id' => $type->getId()]);
    }

    #[Route('/{membershipId}/remove', name: 'app_manage_vt_member_remove', methods: ['POST'], requirements: ['membershipId' => '\d+'])]
    public function remove(Request $request, VolunteerType $type, int $membershipId): Response
    {
        $this->assertCanManage($type);
        $membership = $this->resolveMembership($type, $membershipId);

        if ($membership !== null && $this->isCsrfTokenValid('remove'.$membershipId, (string) $request->request->get('_token'))) {
            $name = $membership->getUser()->getName();
            $this->em->remove($membership);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Removed %s.', $name));
        }

        return $this->redirectToRoute('app_manage_vt_members', ['id' => $type->getId()]);
    }

    private function resolveMembership(VolunteerType $type, int $membershipId): ?UserVolunteerType
    {
        $membership = $this->em->getRepository(UserVolunteerType::class)->find($membershipId);

        return ($membership !== null && $membership->getVolunteerType() === $type) ? $membership : null;
    }

    private function assertCanManage(VolunteerType $type): void
    {
        if ($this->isGranted('volunteertype:manage')) {
            return;
        }

        /** @var User $user */
        $user = $this->getUser();
        $own = $this->memberships->findOneByUserAndType($user, $type);
        if ($own === null || !$own->isSupporter()) {
            throw $this->createAccessDeniedException('You must be a supporter of this type to manage its members.');
        }
    }
}
