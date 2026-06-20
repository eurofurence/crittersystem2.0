<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Repository\UserVolunteerTypeRepository;
use App\Repository\VolunteerTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
    ) {
    }

    #[Route('', name: 'app_membership_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $rows = [];
        foreach ($this->volunteerTypes->findAllOrdered() as $type) {
            $rows[] = ['type' => $type, 'membership' => $this->memberships->findOneByUserAndType($user, $type)];
        }

        return $this->render('membership/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/{id}/join', name: 'app_membership_join', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function join(Request $request, VolunteerType $type): Response
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
                ? \sprintf('Request to join "%s" submitted — awaiting confirmation.', $type->getName())
                : \sprintf('You joined "%s".', $type->getName()));
        }

        return $this->redirectToRoute('app_membership_index');
    }

    #[Route('/{id}/leave', name: 'app_membership_leave', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function leave(Request $request, VolunteerType $type): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('leave'.$type->getId(), (string) $request->request->get('_token'))) {
            $membership = $this->memberships->findOneByUserAndType($user, $type);
            if ($membership !== null) {
                $this->em->remove($membership);
                $this->em->flush();
                $this->addFlash('success', \sprintf('You left "%s".', $type->getName()));
            }
        }

        return $this->redirectToRoute('app_membership_index');
    }
}
