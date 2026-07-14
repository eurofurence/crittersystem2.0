<?php

namespace App\Controller\Manage;

use App\Department\DepartmentImporter;
use App\Entity\Department;
use App\Form\DepartmentType;
use App\Repository\DepartmentRepository;
use App\Repository\ShiftRepository;
use App\Repository\SsoGroupMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/departments')]
#[IsGranted('shift:manage')]
final class DepartmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepartmentRepository $departments,
        private readonly ShiftRepository $shifts,
        private readonly SsoGroupMappingRepository $ssoMappings,
    ) {
    }

    #[Route('', name: 'app_manage_department_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/department/index.html.twig', [
            'departments' => $this->departments->findAllOrdered(),
            'ssoLinkedIds' => $this->ssoMappings->findLinkedDepartmentIds(),
        ]);
    }

    #[Route('/import', name: 'app_manage_department_import', methods: ['POST'])]
    public function import(Request $request, DepartmentImporter $importer): Response
    {
        if (!$this->isCsrfTokenValid('department_import', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_department_index');
        }

        $rows = json_decode((string) $request->request->get('json', ''), true);
        if (!\is_array($rows)) {
            $this->addFlash('danger', 'Invalid JSON: expected an array of departments.');

            return $this->redirectToRoute('app_manage_department_index');
        }

        $result = $importer->import($rows);
        $this->addFlash('success', \sprintf(
            'Imported %d department(s): %d created, %d updated.',
            $result['imported'],
            $result['created'],
            $result['updated'],
        ));
        foreach (\array_slice($result['warnings'], 0, 20) as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('app_manage_department_index');
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

    #[Route('/{id}/edit', name: 'app_manage_department_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Department $department): Response
    {
        // The organizational flag can only change while the department has no shifts.
        $hasShifts = $this->shifts->countForDepartment($department) > 0;
        $form = $this->createForm(DepartmentType::class, $department, ['lock_organizational' => $hasShifts]);
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

    #[Route('/{id}/delete', name: 'app_manage_department_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Department $department): Response
    {
        if ($this->isCsrfTokenValid('delete'.$department->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($department);
            $this->em->flush();
            $this->addFlash('success', 'Department deleted.');
        }

        return $this->redirectToRoute('app_manage_department_index');
    }
}
