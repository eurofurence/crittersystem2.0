<?php

namespace App\Controller\Manage;

use App\Entity\Shift;
use App\Entity\User;
use App\Form\ShiftFormType;
use App\Repository\ShiftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shift CRUD.
 *
 * `shift:manage` is department-scoped, but PrivilegeVoter can only apply that
 * scope when it is handed the resource. The class-level attribute passes none,
 * so it means only "may reach this module" - every action bound to one shift
 * re-checks against that shift, and the listing is filtered to the departments
 * the manager actually holds.
 */
#[Route('/manage/shifts')]
#[IsGranted('shift:manage')]
final class ShiftController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftRepository $shifts,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_manage_shift_index', methods: ['GET'])]
    public function index(): Response
    {
        // findAllOrdered() is every shift in the event, drafts included. Show only
        // the ones this manager may actually open, or the list becomes a directory
        // of other departments' unpublished planning.
        $shifts = array_values(array_filter(
            $this->shifts->findAllOrdered(),
            fn (Shift $shift): bool => $this->isGranted('shift:manage', $shift),
        ));

        return $this->render('manage/shift/index.html.twig', ['shifts' => $shifts]);
    }

    #[Route('/new', name: 'app_manage_shift_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $shift = new Shift();
        $form = $this->createForm(ShiftFormType::class, $shift);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->groupDepartmentMatches($form, $shift)) {
            /** @var User $user */
            $user = $this->getUser();
            $shift->setCreatedBy($user)->setUpdatedBy($user);
            $this->em->persist($shift);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.shift.flash.created', ['%name%' => $shift->getTitle()]));

            return $this->redirectToRoute('app_manage_shift_index');
        }

        return $this->render('manage/shift/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.shift.form.heading_new',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_shift_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift);

        $form = $this->createForm(ShiftFormType::class, $shift);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->groupDepartmentMatches($form, $shift)) {
            /** @var User $user */
            $user = $this->getUser();
            $shift->setUpdatedBy($user);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.shift.flash.updated', ['%name%' => $shift->getTitle()]));

            return $this->redirectToRoute('app_manage_shift_index');
        }

        return $this->render('manage/shift/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.shift.form.heading_edit',
            'shift' => $shift,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_shift_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Shift $shift): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $shift);

        if ($this->isCsrfTokenValid('delete'.$shift->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($shift);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.shift.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_shift_index');
    }

    /**
     * A shift group and its members share one owning department.
     *
     * `shift:manage` is scoped by department, so a group whose members sit in different departments
     * would have no authoritative department to check against and the scope check would pass for
     * anyone. Reported on the field rather than thrown, because it is an ordinary mistake.
     */
    private function groupDepartmentMatches(FormInterface $form, Shift $shift): bool
    {
        $group = $shift->getShiftGroup();
        if ($group === null || $group->getDepartment() === $shift->getDepartment()) {
            return true;
        }

        $form->get('shiftGroup')->addError(new FormError(
            $this->translator->trans('manage.shift.error.group_other_department', ['%name%' => $group->getDepartment()->getName()]),
        ));

        return false;
    }
}
