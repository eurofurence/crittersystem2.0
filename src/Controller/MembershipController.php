<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Repository\UserVolunteerTypeRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\VolunteerTypeVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Volunteer self-service: join/leave volunteer types. Joining a
 * restricted type creates an unconfirmed membership awaiting supporter approval
 */
#[Route('/volunteer-types')]
#[IsGranted('ROLE_USER')]
final class MembershipController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly VolunteerTypeVisibility $typeVisibility,
    ) {
    }

    #[Route('', name: 'app_membership_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $public = [];
        $staff = [];
        foreach ($this->volunteerTypes->findAllOrdered() as $type) {
            if (!$this->typeVisibility->isVisible($type, $user)) {
                continue;
            }
            $row = ['type' => $type, 'membership' => $this->memberships->findOneByUserAndType($user, $type)];
            $type->isStaffOnly() ? $staff[] = $row : $public[] = $row;
        }

        return $this->render('membership/index.html.twig', ['public' => $public, 'staff' => $staff]);
    }

    #[Route('/table', name: 'app_membership_table', methods: ['GET'])]
    public function table(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $rows = [];
        foreach ($this->volunteerTypes->findAllOrdered() as $type) {
            if ($this->typeVisibility->isVisible($type, $user)) {
                $rows[] = ['type' => $type, 'membership' => $this->memberships->findOneByUserAndType($user, $type)];
            }
        }

        return $this->render('membership/table.html.twig', ['rows' => $rows]);
    }

    #[Route('/{id}', name: 'app_membership_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->typeVisibility->isVisible($type, $user)) {
            throw $this->createNotFoundException();
        }

        return $this->render('membership/show.html.twig', [
            'type' => $type,
            'membership' => $this->memberships->findOneByUserAndType($user, $type),
        ]);
    }

    #[Route('/{id}/join', name: 'app_membership_join', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function join(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('join'.$type->getId(), (string) $request->request->get('_token'))
            && $this->memberships->findOneByUserAndType($user, $type) === null
        ) {
            $membership = new UserVolunteerType($user, $type);
            if (!$type->isRestricted()) {
                // Open types are confirmed immediately (no supporter approval needed).
                $membership->setConfirmedBy($user);
            }
            $this->em->persist($membership);
            $this->em->flush();

            $this->addFlash('success', $type->isRestricted()
                ? new TranslatableMessage('membership.flash.join_requested', ['%name%' => $type->getName()])
                : new TranslatableMessage('membership.flash.joined', ['%name%' => $type->getName()]));
        }

        return $this->redirectToRoute('app_membership_index');
    }

    #[Route('/{id}/leave', name: 'app_membership_leave', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function leave(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('leave'.$type->getId(), (string) $request->request->get('_token'))) {
            $membership = $this->memberships->findOneByUserAndType($user, $type);
            if ($membership !== null) {
                $this->em->remove($membership);
                $this->em->flush();
                $this->addFlash('success', new TranslatableMessage('membership.flash.left', ['%name%' => $type->getName()]));
            }
        }

        return $this->redirectToRoute('app_membership_index');
    }
}
