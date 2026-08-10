<?php

namespace App\Controller\Manage;

use App\Entity\Location;
use App\Form\LocationType;
use App\Location\LocationImporter;
use App\Repository\LocationRepository;
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

#[Route('/manage/locations')]
#[IsGranted('location:manage')]
final class LocationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationRepository $locations,
        private readonly LocationImporter $importer,
    ) {
    }

    #[Route('', name: 'app_manage_location_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/location/index.html.twig', [
            'locations' => $this->locations->findAllOrderedByPath(),
        ]);
    }

    #[Route('/export', name: 'app_manage_location_export', methods: ['GET'])]
    public function export(): Response
    {
        $json = json_encode($this->importer->export(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $response = new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'locations.json',
        ));

        return $response;
    }

    #[Route('/import', name: 'app_manage_location_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('location_import', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_location_index');
        }

        // An uploaded file wins over the textarea; fall back to the pasted contents. The modal always
        // submits both fields, so an empty (no-file) upload must fall through, not be read.
        $upload = $request->files->get('file');
        $payload = $upload !== null && $upload->isValid()
            ? (string) $upload->getContent()
            : (string) $request->request->get('json', '');
        if (trim($payload) === '') {
            $this->addFlash('danger', new TranslatableMessage('manage.import.flash.no_json'));

            return $this->redirectToRoute('app_manage_location_index');
        }

        $rows = json_decode($payload, true);
        if (!\is_array($rows) || array_is_list($rows) === false) {
            $this->addFlash('danger', new TranslatableMessage('manage.location.flash.invalid_json'));

            return $this->redirectToRoute('app_manage_location_index');
        }

        $result = $this->importer->import($rows);
        $this->addFlash('success', new TranslatableMessage('manage.location.flash.imported', ['%count%' => $result['imported']]));
        foreach (\array_slice($result['warnings'], 0, 20) as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->redirectToRoute('app_manage_location_index');
    }

    #[Route('/new', name: 'app_manage_location_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $location = new Location('');
        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($location);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.location.flash.created', ['%name%' => $location->getName()]));

            return $this->redirectToRoute('app_manage_location_index');
        }

        return $this->render('manage/location/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.location.form.heading_new',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_location_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Location $location): Response
    {
        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.location.flash.updated', ['%name%' => $location->getName()]));

            return $this->redirectToRoute('app_manage_location_index');
        }

        return $this->render('manage/location/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.location.form.heading_edit',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_location_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Location $location): Response
    {
        if ($this->isCsrfTokenValid('delete'.$location->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($location);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.location.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_location_index');
    }
}
