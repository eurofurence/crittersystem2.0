<?php

namespace App\Controller\Manage;

use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Repository\UserVolunteerTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Member management for a volunteer type. Open to holders of
 * admin_volunteer_types and to supporters of the type itself
 */
#[Route('/manage/volunteer-types/{id}/members', requirements: ['id' => Requirement::UUID])]
#[IsGranted('ROLE_USER')]
final class VolunteerTypeMembersController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserVolunteerTypeRepository $memberships,
    ) {
    }

    #[Route('', name: 'app_manage_vt_members', methods: ['GET'])]
    public function index(#[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type): Response
    {
        $this->assertCanManage($type);

        return $this->render('manage/volunteer_type/members.html.twig', [
            'type' => $type,
            'members' => $this->memberships->findByVolunteerType($type),
        ]);
    }

    #[Route('/{membershipId}/confirm', name: 'app_manage_vt_member_confirm', methods: ['POST'], requirements: ['membershipId' => Requirement::UUID])]
    public function confirm(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type, string $membershipId): Response
    {
        $this->assertCanManage($type);
        $membership = $this->resolveMembership($type, $membershipId);

        if ($membership !== null && $this->isCsrfTokenValid('confirm'.$membershipId, (string) $request->request->get('_token'))) {
            /** @var User $actor */
            $actor = $this->getUser();
            $membership->setConfirmedBy($actor);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.volunteer_type.members.flash.confirmed', ['%name%' => $membership->getUser()->getName()]));
        }

        return $this->redirectToRoute('app_manage_vt_members', ['id' => $type->getUuid()]);
    }

    #[Route('/{membershipId}/supporter', name: 'app_manage_vt_member_supporter', methods: ['POST'], requirements: ['membershipId' => Requirement::UUID])]
    public function toggleSupporter(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type, string $membershipId): Response
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
            $this->addFlash('success', $membership->isSupporter()
                ? new TranslatableMessage('manage.volunteer_type.members.flash.now_supporter', ['%name%' => $membership->getUser()->getName()])
                : new TranslatableMessage('manage.volunteer_type.members.flash.not_supporter', ['%name%' => $membership->getUser()->getName()]));
        }

        return $this->redirectToRoute('app_manage_vt_members', ['id' => $type->getUuid()]);
    }

    #[Route('/{membershipId}/remove', name: 'app_manage_vt_member_remove', methods: ['POST'], requirements: ['membershipId' => Requirement::UUID])]
    public function remove(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type, string $membershipId): Response
    {
        $this->assertCanManage($type);
        $membership = $this->resolveMembership($type, $membershipId);

        if ($membership !== null && $this->isCsrfTokenValid('remove'.$membershipId, (string) $request->request->get('_token'))) {
            $name = $membership->getUser()->getName();
            $this->em->remove($membership);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.volunteer_type.members.flash.removed', ['%name%' => $name]));
        }

        return $this->redirectToRoute('app_manage_vt_members', ['id' => $type->getUuid()]);
    }

    private function resolveMembership(VolunteerType $type, string $membershipId): ?UserVolunteerType
    {
        $membership = $this->em->getRepository(UserVolunteerType::class)->findOneBy(['uuid' => $membershipId]);

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
