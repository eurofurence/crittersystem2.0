<?php

namespace App\Controller\Manage;

use App\Entity\Shift;
use App\Entity\ShiftGroup;
use App\Form\ShiftGroupType;
use App\Enum\ShiftAudience;
use App\Repository\ShiftGroupRepository;
use App\Repository\ShiftRepository;
use App\Repository\ShiftTaskRepository;
use App\Service\DisplaySettings;
use App\Service\Shift\ShiftGroupAudit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Managing shift groups: shifts a volunteer can only take together.
 *
 * `shift:manage` is department-scoped and PrivilegeVoter only applies that scope when it is handed
 * the resource, so the class-level attribute below means no more than "may reach this module".
 * Every action bound to one group re-checks against that group's department, and the listing is
 * filtered to the departments the manager actually holds - otherwise a manager delegated to one
 * department could rewire another department's commitments.
 */
#[Route('/manage/shift-groups')]
#[IsGranted('shift:manage')]
final class ShiftGroupController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftGroupRepository $shiftGroups,
        private readonly ShiftRepository $shifts,
        private readonly ShiftGroupAudit $audit,
        private readonly ShiftTaskRepository $shiftTasks,
        private readonly DisplaySettings $display,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_manage_shift_group_index', methods: ['GET'])]
    public function index(): Response
    {
        $groups = array_values(array_filter(
            $this->shiftGroups->findAllOrdered(),
            fn (ShiftGroup $group): bool => $this->isGranted('shift:manage', $group->getDepartment()),
        ));

        $warnings = [];
        foreach ($groups as $group) {
            $warnings[$group->getId()] = $this->audit->warningsFor($group);
        }

        return $this->render('manage/shift_group/index.html.twig', [
            'groups' => $groups,
            'warnings' => $warnings,
        ]);
    }

    /**
     * The choice list only offers departments the manager holds, but the submitted id is user
     * input and is checked again before the group is created.
     */
    #[Route('/new', name: 'app_manage_shift_group_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $form = $this->createForm(ShiftGroupType::class, null, ['group_department' => null]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $this->denyAccessUnlessGranted('shift:manage', $data['department']);

            $group = new ShiftGroup($data['department'], $data['name']);
            $group->setDescription($data['description']);
            $this->em->persist($group);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.shift_group.flash.created', ['%name%' => $group->getName()]));

            return $this->redirectToRoute('app_manage_shift_group_edit', ['id' => $group->getUuid()]);
        }

        return $this->render('manage/shift_group/form.html.twig', [
            'form' => $form,
            'group' => null,
            'heading' => 'manage.shift_group.form.heading_new',
        ]);
    }

    /**
     * The submitted department is authorized as well as the current one: moving a group into a
     * department the manager does not hold would hand it away and strand its members in the old one.
     */
    #[Route('/{id}/edit', name: 'app_manage_shift_group_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftGroup $group): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $group->getDepartment());

        $form = $this->createForm(ShiftGroupType::class, [
            'name' => $group->getName(),
            'description' => $group->getDescription(),
            'department' => $group->getDepartment(),
        ], ['group_department' => $group->getDepartment()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $this->denyAccessUnlessGranted('shift:manage', $data['department']);

            if ($data['department'] !== $group->getDepartment() && $group->getShifts()->count() > 0) {
                $this->addFlash('danger', new TranslatableMessage('manage.shift_group.flash.department_locked'));

                return $this->redirectToRoute('app_manage_shift_group_edit', ['id' => $group->getUuid()]);
            }

            $group->setName($data['name'])->setDescription($data['description'])->setDepartment($data['department']);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.shift_group.flash.updated', ['%name%' => $group->getName()]));

            return $this->redirectToRoute('app_manage_shift_group_edit', ['id' => $group->getUuid()]);
        }

        return $this->render('manage/shift_group/form.html.twig', [
            'form' => $form,
            'group' => $group,
            'members' => $this->audit->membersOf($group),
            'candidates' => $this->candidates($group, $request),
            'warnings' => $this->audit->warningsFor($group),
            'heading' => 'manage.shift_group.form.heading_edit',
        ] + $this->pickerOptions($group));
    }

    /**
     * The candidate list on its own, re-rendered as the manager works the filters.
     *
     * A fragment, never a page: the edit screen also holds the group's name and description, and
     * reloading the whole thing to filter would throw away anything typed there but not saved.
     */
    #[Route('/{id}/candidates', name: 'app_manage_shift_group_candidates', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function candidateList(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftGroup $group): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $group->getDepartment());

        return $this->render('manage/shift_group/_candidates.html.twig', [
            'group' => $group,
            'candidates' => $this->candidates($group, $request),
        ]);
    }

    /** The member list on its own, re-rendered after shifts are added. */
    #[Route('/{id}/members-list', name: 'app_manage_shift_group_member_list', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function memberList(#[MapEntity(mapping: ['id' => 'uuid'])] ShiftGroup $group): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $group->getDepartment());

        return $this->render('manage/shift_group/_members.html.twig', [
            'group' => $group,
            'members' => $this->audit->membersOf($group),
            'warnings' => $this->audit->warningsFor($group),
        ]);
    }

    /**
     * Candidates for the picker, narrowed by whatever the filter bar sent.
     *
     * The `past` flag is cast rather than read with getBoolean(), which throws a BadRequestException
     * on a value it cannot convert: an unset filter arrives from the picker as an empty string,
     * which is not a malformed request.
     *
     * @return list<Shift>
     */
    private function candidates(ShiftGroup $group, Request $request): array
    {
        $tz = $this->display->timezone();
        [$dayFrom, $dayTo] = $this->dayRange((string) $request->query->get('day'), $tz);

        $task = $this->shiftTasks->findOneByUuid((string) $request->query->get('type'));

        return $this->shifts->findGroupCandidates(
            $group->getDepartment(),
            $group,
            $dayFrom,
            $dayTo,
            ShiftAudience::tryFrom((string) $request->query->get('audience')),
            $task,
            trim((string) $request->query->get('q')),
            (bool) (int) $request->query->get('past'),
        );
    }

    /**
     * The filter bar's option lists, drawn from the department's own shifts so a manager cannot pick
     * a combination that could only ever return nothing.
     *
     * Audiences keep enum declaration order, so the list reads the same as everywhere else audiences
     * appear.
     *
     * @return array{days: list<string>, audiences: list<ShiftAudience>, shiftTasks: list<\App\Entity\ShiftTask>}
     */
    private function pickerOptions(ShiftGroup $group): array
    {
        $tz = $this->display->timezone();
        $days = [];
        $audiences = [];
        $tasks = [];

        foreach ($this->shifts->findBy(['department' => $group->getDepartment()], ['startsAt' => 'ASC']) as $shift) {
            $days[$shift->getStartsAt()->setTimezone($tz)->format('Y-m-d')] = true;
            $audiences[$shift->getAudience()->value] = $shift->getAudience();
            if (($task = $shift->getShiftTask()) !== null) {
                $tasks[(string) $task->getUuid()] = $task;
            }
        }
        ksort($days);
        uasort($tasks, static fn ($a, $b): int => strcasecmp($a->displayName(), $b->displayName()));

        return [
            'days' => array_keys($days),
            'audiences' => array_values(array_filter(
                ShiftAudience::cases(),
                static fn (ShiftAudience $a): bool => isset($audiences[$a->value]),
            )),
            'shiftTasks' => array_values($tasks),
        ];
    }

    /**
     * A calendar day as a half-open UTC range.
     *
     * Anchored on local midnight in the display timezone: shifts are stored as absolute instants, so
     * a day boundary taken in UTC would put an early-morning local shift on the previous day.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function dayRange(string $day, \DateTimeZone $tz): array
    {
        if ($day === '') {
            return [null, null];
        }

        try {
            $from = (new \DateTimeImmutable($day, $tz))->setTime(0, 0);
        } catch (\Exception) {
            return [null, null];
        }

        return [$from, $from->modify('+1 day')];
    }

    /**
     * Add shifts to the group, several at a time.
     *
     * Answers JSON: the picker posts over fetch so the manager keeps the filters and the selection
     * they built up, and so the partial-commitment question can be asked before anything is written.
     *
     * Refuses a shift from another department, or one already in a different group. A group has one
     * owning department because `shift:manage` is scoped by department, and a group spanning two
     * would have no authoritative department to check against. Any bad id refuses the whole batch:
     * applying half of a selection and reporting the rest leaves the manager guessing which is which.
     *
     * Volunteers already signed up to the other members are NOT signed up to the new shifts.
     * Capacity, availability, overlaps and hours all have to be re-checked per volunteer, and putting
     * somebody on a shift they never applied to is the kind of surprise this system must not produce.
     * The manager confirms first and can then assign them individually.
     */
    #[Route('/{id}/members', name: 'app_manage_shift_group_member_add', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function addMember(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftGroup $group): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $group->getDepartment());

        if (!$this->isCsrfTokenValid('group-member'.$group->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('manage.shift_group.flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        $selection = [];
        foreach ((array) $request->request->all('shifts') as $uuid) {
            $shift = $this->shifts->findOneByUuid((string) $uuid);

            if ($shift === null) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('manage.shift_group.flash.unknown_shift')]);
            }
            if ($shift->getDepartment() !== $group->getDepartment()) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans('manage.shift_group.flash.other_department')]);
            }
            if ($shift->getShiftGroup() !== null && $shift->getShiftGroup() !== $group) {
                return $this->json(['ok' => false, 'error' => $this->translator->trans(
                    'manage.shift_group.flash.already_grouped',
                    ['%name%' => $shift->getShiftGroup()->getName()],
                )]);
            }
            if ($shift->getShiftGroup() !== $group) {
                $selection[] = $shift;
            }
        }

        if ($selection === []) {
            return $this->json(['ok' => false, 'error' => $this->translator->trans('manage.shift_group.flash.nothing_selected')]);
        }

        if (!$request->request->getBoolean('confirm')) {
            $partial = $this->audit->partiallyAssignedCount(array_merge($this->audit->membersOf($group), $selection));
            if ($partial > 0) {
                return $this->json(['ok' => false, 'confirm' => $this->translator->trans(
                    'manage.shift_group.confirm.partial_members',
                    ['%count%' => $partial],
                )]);
            }
        }

        foreach ($selection as $shift) {
            $group->addShift($shift);
        }
        $this->em->flush();

        return $this->json(['ok' => true, 'added' => \count($selection)]);
    }

    #[Route('/{id}/members/{shiftId}/remove', name: 'app_manage_shift_group_member_remove', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'shiftId' => Requirement::UUID])]
    public function removeMember(
        Request $request,
        #[MapEntity(mapping: ['id' => 'uuid'])] ShiftGroup $group,
        string $shiftId,
    ): Response {
        $this->denyAccessUnlessGranted('shift:manage', $group->getDepartment());

        if ($this->isCsrfTokenValid('group-member'.$group->getId(), (string) $request->request->get('_token'))) {
            $shift = $this->shifts->findOneByUuid($shiftId);
            if ($shift instanceof Shift && $shift->getShiftGroup() === $group) {
                $group->removeShift($shift);
                $this->em->flush();
                $this->addFlash('success', new TranslatableMessage('manage.shift_group.flash.member_removed', ['%name%' => $shift->getTitle()]));
            }
        }

        return $this->redirectToRoute('app_manage_shift_group_edit', ['id' => $group->getUuid()]);
    }

    /**
     * Delete the group. Its shifts survive and become ungrouped, and the sign-ups volunteers already
     * hold on each of them stay: they applied to real shifts, and only the obligation between them
     * disappears.
     */
    #[Route('/{id}/delete', name: 'app_manage_shift_group_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftGroup $group): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $group->getDepartment());

        if ($this->isCsrfTokenValid('delete'.$group->getId(), (string) $request->request->get('_token'))) {
            foreach ($group->getShifts()->toArray() as $shift) {
                $shift->setShiftGroup(null);
            }
            $this->em->remove($group);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.shift_group.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_shift_group_index');
    }

}
