<?php

namespace App\Controller\Manage;

use App\Entity\VolunteerType;
use App\Form\VolunteerTypeType;
use App\Repository\VolunteerTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/volunteer-types')]
#[IsGranted('volunteertype:manage')]
final class VolunteerTypeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VolunteerTypeRepository $volunteerTypes,
    ) {
    }

    #[Route('', name: 'app_manage_volunteer_type_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/volunteer_type/index.html.twig', [
            'volunteerTypes' => $this->volunteerTypes->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_manage_volunteer_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $volunteerType = new VolunteerType('');
        $form = $this->createForm(VolunteerTypeType::class, $volunteerType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($volunteerType);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Volunteer type "%s" created.', $volunteerType->getName()));

            return $this->redirectToRoute('app_manage_volunteer_type_index');
        }

        return $this->render('manage/volunteer_type/form.html.twig', [
            'form' => $form,
            'heading' => 'New volunteer type',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_volunteer_type_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, VolunteerType $volunteerType): Response
    {
        $form = $this->createForm(VolunteerTypeType::class, $volunteerType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', \sprintf('Volunteer type "%s" updated.', $volunteerType->getName()));

            return $this->redirectToRoute('app_manage_volunteer_type_index');
        }

        return $this->render('manage/volunteer_type/form.html.twig', [
            'form' => $form,
            'heading' => 'Edit volunteer type',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_volunteer_type_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, VolunteerType $volunteerType): Response
    {
        if ($this->isCsrfTokenValid('delete'.$volunteerType->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($volunteerType);
            $this->em->flush();
            $this->addFlash('success', 'Volunteer type deleted.');
        }

        return $this->redirectToRoute('app_manage_volunteer_type_index');
    }
}
