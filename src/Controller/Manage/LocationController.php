<?php

namespace App\Controller\Manage;

use App\Entity\Location;
use App\Form\LocationType;
use App\Repository\LocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/locations')]
#[IsGranted('admin_rooms')]
final class LocationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LocationRepository $locations,
    ) {
    }

    #[Route('', name: 'app_manage_location_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/location/index.html.twig', [
            'locations' => $this->locations->findAllOrdered(),
        ]);
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
            $this->addFlash('success', \sprintf('Location "%s" created.', $location->getName()));

            return $this->redirectToRoute('app_manage_location_index');
        }

        return $this->render('manage/location/form.html.twig', [
            'form' => $form,
            'heading' => 'New location',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_location_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Location $location): Response
    {
        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', \sprintf('Location "%s" updated.', $location->getName()));

            return $this->redirectToRoute('app_manage_location_index');
        }

        return $this->render('manage/location/form.html.twig', [
            'form' => $form,
            'heading' => 'Edit location',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_location_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Location $location): Response
    {
        if ($this->isCsrfTokenValid('delete'.$location->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($location);
            $this->em->flush();
            $this->addFlash('success', 'Location deleted.');
        }

        return $this->redirectToRoute('app_manage_location_index');
    }
}
