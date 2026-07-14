<?php

namespace App\Controller\Manage;

use App\Entity\ShiftTask;
use App\Form\ShiftTaskType;
use App\Repository\ShiftTaskRepository;
use App\Service\Shift\ShiftTaskAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Managing shift tasks.
 *
 * `shift:manage` is department-scoped, and the class-level check below passes for anyone holding it
 * in ANY department — so it only gets the user through the door. Every action re-checks the task's
 * own department through {@see ShiftTaskAccess}: a manager delegated to one department must not be
 * able to change another department's tasks, nor the global ones every department shares.
 */
#[Route('/manage/shift-tasks')]
#[IsGranted('shift:manage')]
final class ShiftTaskController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftTaskRepository $shiftTasks,
        private readonly ShiftTaskAccess $access,
    ) {
    }

    #[Route('', name: 'app_manage_shift_task_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/shift_task/index.html.twig', [
            'shiftTasks' => $this->access->visible($this->shiftTasks->findAllOrdered()),
            'canCreate' => $this->access->isAdmin() || $this->access->manageableDepartments() !== [],
        ]);
    }

    #[Route('/new', name: 'app_manage_shift_task_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $shiftTask = new ShiftTask('');
        $form = $this->createForm(ShiftTaskType::class, $shiftTask);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // The form only offers departments the user may manage, but a submitted id is user
            // input: re-check it here rather than trusting the choice list.
            if (!$this->access->canManageDepartment($shiftTask->getDepartment())) {
                throw $this->createAccessDeniedException('You cannot create a shift task for that department.');
            }

            $this->em->persist($shiftTask);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Shift task "%s" created.', $shiftTask->getName()));

            return $this->redirectToRoute('app_manage_shift_task_index');
        }

        return $this->render('manage/shift_task/form.html.twig', [
            'form' => $form,
            'heading' => 'New shift task',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_shift_task_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftTask $shiftTask): Response
    {
        if (!$this->access->canManage($shiftTask)) {
            throw $this->createAccessDeniedException('This shift task belongs to another department.');
        }

        $form = $this->createForm(ShiftTaskType::class, $shiftTask);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Moving a task into a department the user does not manage would hand it away.
            if (!$this->access->canManageDepartment($shiftTask->getDepartment())) {
                throw $this->createAccessDeniedException('You cannot move a shift task to that department.');
            }

            $this->em->flush();
            $this->addFlash('success', \sprintf('Shift task "%s" updated.', $shiftTask->getName()));

            return $this->redirectToRoute('app_manage_shift_task_index');
        }

        return $this->render('manage/shift_task/form.html.twig', [
            'form' => $form,
            'heading' => 'Edit shift task',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_shift_task_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] ShiftTask $shiftTask): Response
    {
        if (!$this->access->canManage($shiftTask)) {
            throw $this->createAccessDeniedException('This shift task belongs to another department.');
        }

        if ($this->isCsrfTokenValid('delete'.$shiftTask->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($shiftTask);
            $this->em->flush();
            $this->addFlash('success', 'Shift task deleted.');
        }

        return $this->redirectToRoute('app_manage_shift_task_index');
    }
}
