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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/manage/departments')]
#[IsGranted('shift:manage')]
final class DepartmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepartmentRepository $departments,
        private readonly ShiftRepository $shifts,
        private readonly SsoGroupMappingRepository $ssoMappings,
        private readonly DepartmentImporter $importer,
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

    #[Route('/export', name: 'app_manage_department_export', methods: ['GET'])]
    public function export(): Response
    {
        $json = json_encode($this->importer->export(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $response = new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'departments.json',
        ));

        return $response;
    }

    #[Route('/import', name: 'app_manage_department_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('department_import', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_department_index');
        }

        // An uploaded file wins over the textarea; fall back to the pasted contents. The modal always
        // submits both fields, so an empty (no-file) upload must fall through, not be read.
        $upload = $request->files->get('file');
        $payload = $upload !== null && $upload->isValid()
            ? (string) $upload->getContent()
            : (string) $request->request->get('json', '');
        if (trim($payload) === '') {
            $this->addFlash('danger', new TranslatableMessage('manage.import.flash.no_json'));

            return $this->redirectToRoute('app_manage_department_index');
        }

        $rows = json_decode($payload, true);
        if (!\is_array($rows) || array_is_list($rows) === false) {
            $this->addFlash('danger', new TranslatableMessage('manage.department.flash.invalid_json'));

            return $this->redirectToRoute('app_manage_department_index');
        }

        $result = $this->importer->import($rows);
        $this->addFlash('success', new TranslatableMessage('manage.department.flash.imported', [
            '%imported%' => $result['imported'],
            '%created%' => $result['created'],
            '%updated%' => $result['updated'],
        ]));
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
            $this->addFlash('success', new TranslatableMessage('manage.department.flash.created', ['%name%' => $department->getName()]));

            return $this->redirectToRoute('app_manage_department_index');
        }

        return $this->render('manage/department/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.department.form.heading_new',
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
            $this->addFlash('success', new TranslatableMessage('manage.department.flash.updated', ['%name%' => $department->getName()]));

            return $this->redirectToRoute('app_manage_department_index');
        }

        return $this->render('manage/department/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.department.form.heading_edit',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_department_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Department $department): Response
    {
        if ($this->isCsrfTokenValid('delete'.$department->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($department);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.department.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_department_index');
    }
}
