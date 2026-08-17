<?php

namespace App\Controller\Manage;

use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Repository\UserRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Service\UserSearchResultFormatter;
use App\Service\VolunteerTypeVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly UserRepository $users,
        private readonly VolunteerTypeVisibility $typeVisibility,
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

    /**
     * Type-ahead source for the add-member picker. Name-only matching: widening it to e-mail
     * would turn a picker every supporter can reach into an address harvester.
     */
    #[Route('/search', name: 'app_manage_vt_member_search', methods: ['GET'])]
    public function search(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type, UserSearchResultFormatter $formatter): JsonResponse
    {
        $this->assertCanManage($type);

        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return new JsonResponse(['results' => []]);
        }

        $seen = [];
        foreach ($this->memberships->findByVolunteerType($type) as $membership) {
            $seen[$membership->getUser()->getId()] = true;
        }

        $matches = array_filter(
            $this->users->searchByName($q),
            static fn (User $user): bool => !isset($seen[$user->getId()]),
        );

        return new JsonResponse($formatter->results($matches));
    }

    /**
     * Puts the picked users in the type. Being added by a manager is itself the approval, so the
     * membership is confirmed even for a restricted type and the volunteer never has to apply.
     *
     * A staff-only or department-only type answers 404 to anyone outside it, so a volunteer added
     * to one holds a membership they can neither open nor leave. That stays allowed - the manager
     * may be staffing ahead of a promotion - but it is reported back rather than done silently.
     *
     * $handled guards against a uuid repeated within one submission: the membership lookup runs
     * against the database and cannot see a persist from an earlier pass of this loop, so the
     * duplicate would reach flush and break the user/type unique constraint.
     */
    #[Route('/add', name: 'app_manage_vt_member_add', methods: ['POST'])]
    public function add(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type): Response
    {
        $this->assertCanManage($type);

        if (!$this->isCsrfTokenValid('addmember'.$type->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_vt_members', ['id' => $type->getUuid()]);
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $added = [];
        $unreachable = [];
        $handled = [];

        foreach ($request->request->all('users') as $uuid) {
            $user = \is_string($uuid) && $uuid !== '' ? $this->users->findOneByUuid($uuid) : null;
            if ($user === null || isset($handled[$user->getId()]) || $this->memberships->findOneByUserAndType($user, $type) !== null) {
                continue;
            }
            $handled[$user->getId()] = true;

            $membership = new UserVolunteerType($user, $type);
            $membership->setConfirmedBy($actor);
            $this->em->persist($membership);
            $added[] = $user->getName();

            if (!$this->typeVisibility->isVisible($type, $user)) {
                $unreachable[] = $user->getName();
            }
        }

        if ($added !== []) {
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.volunteer_type.members.flash.added', ['%names%' => implode(', ', $added)]));
        }
        if ($unreachable !== []) {
            $this->addFlash('warning', new TranslatableMessage('manage.volunteer_type.members.flash.added_hidden', ['%names%' => implode(', ', $unreachable)]));
        }

        return $this->redirectToRoute('app_manage_vt_members', ['id' => $type->getUuid()]);
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

    /**
     * Promoting somebody to supporter confirms an unconfirmed membership at the same time: a
     * supporter decides on other people's requests for this type.
     */
    #[Route('/{membershipId}/supporter', name: 'app_manage_vt_member_supporter', methods: ['POST'], requirements: ['membershipId' => Requirement::UUID])]
    public function toggleSupporter(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $type, string $membershipId): Response
    {
        $this->assertCanManage($type);
        $membership = $this->resolveMembership($type, $membershipId);

        if ($membership !== null && $this->isCsrfTokenValid('supporter'.$membershipId, (string) $request->request->get('_token'))) {
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
