<?php

namespace App\Controller\Manage;

use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\Assignment\ManualAssignmentService;
use App\Service\NoShowBanService;
use App\Service\Shift\ShiftGroupResolver;
use App\Service\Shift\VolunteerTypeOrdering;
use App\Service\ShiftSignupService;
use App\Service\UserSearchResultFormatter;
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
 * Staffing screens for a single shift.
 *
 * `shift:manage` is department-scoped, and PrivilegeVoter only enforces that
 * scope when it is given the resource. The class-level attribute below passes
 * none, so it means no more than "may reach the shift-management module" -
 * every action here must additionally check against the shift it acts on, or a
 * manager scoped to one department reaches every shift in the event.
 */
#[Route('/manage/shifts')]
#[IsGranted('shift:manage')]
final class ShiftStaffingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftSignupService $signup,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly UserRepository $users,
        private readonly ShiftEntryRepository $entries,
        private readonly NoShowBanService $noShowBans,
        private readonly ManualAssignmentService $assignments,
        private readonly ShiftGroupResolver $groups,
        private readonly VolunteerTypeOrdering $typeOrder,
    ) {
    }

    #[Route('/{id}/staffing', name: 'app_manage_shift_needs', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function staffing(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift);

        // How much of a grouped commitment each volunteer actually holds. A manager who split a group
        // (or a volunteer added before the group existed) leaves somebody on part of it, and that has
        // to stay visible rather than become invisible history.
        $members = $this->groups->membersFor($shift);
        $partial = [];
        foreach ($shift->getEntries() as $entry) {
            $held = \count($this->groups->entriesFor($shift, $entry->getUser()));
            if ($held < \count($members)) {
                $partial[$entry->getId()] = $held;
            }
        }

        return $this->render('manage/shift/staffing.html.twig', [
            'shift' => $shift,
            'availability' => $this->signup->availability($shift),
            'shiftNeeds' => $shift->getNeededVolunteerTypes(),
            'entries' => $shift->getEntries(),
            'volunteerTypes' => $this->typeOrder->forDepartment(
                $this->volunteerTypes->findAllOrderedWithDepartments(),
                $shift->getDepartment(),
            ),
            'groupMembers' => $members,
            'partialHeld' => $partial,
        ]);
    }

    #[Route('/{id}/staffing/search', name: 'app_manage_shift_staff_search', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function staffingSearch(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, UserSearchResultFormatter $formatter): JsonResponse
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift);

        $q = trim((string) $request->query->get('q', ''));

        return new JsonResponse($formatter->results($q === '' ? [] : $this->users->searchByName($q)));
    }

    #[Route('/{id}/assign', name: 'app_manage_shift_assign', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function assign(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift);

        if ($this->isCsrfTokenValid('assign'.$shift->getId(), (string) $request->request->get('_token'))) {
            $user = $this->users->findOneByUuid(trim((string) $request->request->get('user')));
            $type = $this->volunteerTypes->findOneByUuid((string) $request->request->get('volunteer_type'));

            if ($user === null || $type === null) {
                $this->addFlash('danger', new TranslatableMessage('manage.shift.staffing.flash.choose_volunteer_role'));
            } elseif ($this->entries->findOneByShiftAndUser($shift, $user) !== null) {
                $this->addFlash('warning', new TranslatableMessage('manage.shift.staffing.flash.already_on_shift', ['%name%' => $user->getName()]));
            } else {
                // Manager override: capacity and membership are deliberately not enforced, so the
                // availability/hours warnings are overridden rather than refused. This goes through
                // ManualAssignmentService and not a bare persist, so the assignment is audited, the
                // live signal fires, and a grouped shift takes the volunteer onto every member.
                $groupSplit = $request->request->getBoolean('group_split');
                $this->assignments->assign($shift, $user, $type, override: true, actor: $this->getUser(), groupSplit: $groupSplit);

                $siblings = $groupSplit ? [] : $this->groups->siblingsOf($shift);
                $this->addFlash('success', $siblings === []
                    ? new TranslatableMessage('manage.shift.staffing.flash.assigned', ['%name%' => $user->getName(), '%role%' => $type->getName()])
                    : new TranslatableMessage('manage.shift.staffing.flash.assigned_group', ['%name%' => $user->getName(), '%role%' => $type->getName(), '%count%' => \count($siblings) + 1]));
            }
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $shift->getUuid()]);
    }

    #[Route('/entries/{id}/unassign', name: 'app_manage_shift_entry_unassign', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function unassign(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftEntry $entry): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $entry->getShift());

        $shiftId = $entry->getShift()->getUuid();
        if ($this->isCsrfTokenValid('unassign'.$entry->getId(), (string) $request->request->get('_token'))) {
            $name = $entry->getUser()->getName();
            $groupSplit = $request->request->getBoolean('group_split');
            $held = $groupSplit ? 1 : \count($this->groups->entriesFor($entry->getShift(), $entry->getUser()));

            $this->assignments->remove($entry, groupSplit: $groupSplit);

            $this->addFlash('success', $held > 1
                ? new TranslatableMessage('manage.shift.staffing.flash.removed_group', ['%name%' => $name, '%count%' => $held])
                : new TranslatableMessage('manage.shift.staffing.flash.removed', ['%name%' => $name]));
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $shiftId]);
    }

    #[Route('/{id}/needs', name: 'app_manage_shift_need_add', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function addNeed(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift);

        if ($this->isCsrfTokenValid('need-add'.$shift->getId(), (string) $request->request->get('_token'))) {
            $type = $this->volunteerTypes->findOneByUuid((string) $request->request->get('volunteer_type'));
            $count = max(1, (int) $request->request->get('count', 1));

            if ($type === null) {
                $this->addFlash('danger', new TranslatableMessage('manage.shift.staffing.flash.choose_type'));
            } elseif ($shift->getNeededVolunteerTypes()->exists(fn ($k, NeededVolunteerType $n) => $n->getVolunteerType() === $type)) {
                $this->addFlash('warning', new TranslatableMessage('manage.shift.staffing.flash.type_already_listed', ['%name%' => $type->getName()]));
            } else {
                $need = new NeededVolunteerType($type, $count);
                $shift->addNeededVolunteerType($need);
                $this->em->persist($need);
                $this->em->flush();
                $this->addFlash('success', new TranslatableMessage('manage.shift.staffing.flash.need_added', ['%count%' => $count, '%name%' => $type->getName()]));
            }
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $shift->getUuid()]);
    }

    #[Route('/{id}/needs/{needId}/delete', name: 'app_manage_shift_need_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'needId' => Requirement::UUID])]
    public function deleteNeed(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift, string $needId): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift);

        if ($this->isCsrfTokenValid('need-del'.$needId, (string) $request->request->get('_token'))) {
            $need = $this->em->getRepository(NeededVolunteerType::class)->findOneBy(['uuid' => $needId]);
            if ($need !== null && $need->getShift() === $shift) {
                $this->em->remove($need);
                $this->em->flush();
                $this->addFlash('success', new TranslatableMessage('manage.shift.staffing.flash.requirement_removed'));
            }
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $shift->getUuid()]);
    }

    #[Route('/entries/{id}/noshow', name: 'app_manage_shift_entry_noshow', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function toggleNoshow(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftEntry $entry): Response
    {
        // Scoped to the entry's own shift: a no-show can trigger the automatic
        // ban, so this must never be reachable for another department's shift.
        $this->denyAccessUnlessGranted('shift:manage', $entry->getShift());

        if ($this->isCsrfTokenValid('noshow'.$entry->getId(), (string) $request->request->get('_token'))) {
            $entry->setNoshow(!$entry->isNoshow());
            $entry->setNoshowComment($entry->isNoshow() ? (string) $request->request->get('comment') ?: null : null);
            $this->em->flush();

            // Reaching the configured no-show threshold locks the account.
            if ($entry->isNoshow() && $this->noShowBans->evaluate($entry->getUser())) {
                $this->addFlash('warning', new TranslatableMessage('manage.shift.staffing.flash.auto_banned'));
            } else {
                $this->addFlash('success', $entry->isNoshow()
                    ? new TranslatableMessage('manage.shift.staffing.flash.marked_noshow')
                    : new TranslatableMessage('manage.shift.staffing.flash.noshow_cleared'));
            }
        }

        return $this->redirectToRoute('app_manage_shift_needs', ['id' => $entry->getShift()->getUuid()]);
    }
}
