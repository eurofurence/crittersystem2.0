<?php

namespace App\Controller\Manage;

use App\Entity\Department;
use App\Form\DepartmentType;
use App\Repository\DepartmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/departments')]
#[IsGranted('shift:manage')]
final class DepartmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepartmentRepository $departments,
    ) {
    }

    #[Route('', name: 'app_manage_department_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/department/index.html.twig', [
            'departments' => $this->departments->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_manage_department_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $department = new Department('', '');
        $form = $this->createForm(DepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($department);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Department "%s" created.', $department->getName()));

            return $this->redirectToRoute('app_manage_department_index');
        }

        return $this->render('manage/department/form.html.twig', [
            'form' => $form,
            'heading' => 'New department',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_department_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Department $department): Response
    {
        $form = $this->createForm(DepartmentType::class, $department);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', \sprintf('Department "%s" updated.', $department->getName()));

            return $this->redirectToRoute('app_manage_department_index');
        }

        return $this->render('manage/department/form.html.twig', [
            'form' => $form,
            'heading' => 'Edit department',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_department_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Department $department): Response
    {
        if ($this->isCsrfTokenValid('delete'.$department->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($department);
            $this->em->flush();
            $this->addFlash('success', 'Department deleted.');
        }

        return $this->redirectToRoute('app_manage_department_index');
    }
}
