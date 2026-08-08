<?php

namespace App\Controller\Manage;

use App\Entity\VolunteerType;
use App\Form\VolunteerTypeType;
use App\Repository\CertificationRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Repository\VolunteerTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/manage/volunteer-types')]
#[IsGranted('volunteertype:manage')]
final class VolunteerTypeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly CertificationRepository $certifications,
        private readonly UserVolunteerTypeRepository $memberships,
    ) {
    }

    /**
     * All certifications keyed by id, so the form can pair each checkbox
     * (whose value is the certification id) with its entity for the card.
     *
     * @return array<int, \App\Entity\Certification>
     */
    private function certificationsById(): array
    {
        $byId = [];
        foreach ($this->certifications->findAllOrdered() as $certification) {
            $byId[$certification->getId()] = $certification;
        }

        return $byId;
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
            $this->addFlash('success', new TranslatableMessage('manage.volunteer_type.flash.created', ['%name%' => $volunteerType->getName()]));

            return $this->redirectToRoute('app_manage_volunteer_type_index');
        }

        return $this->render('manage/volunteer_type/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.volunteer_type.form.heading_new',
            'certifications' => $this->certificationsById(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_volunteer_type_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $volunteerType): Response
    {
        $form = $this->createForm(VolunteerTypeType::class, $volunteerType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.volunteer_type.flash.updated', ['%name%' => $volunteerType->getName()]));

            return $this->redirectToRoute('app_manage_volunteer_type_index');
        }

        return $this->render('manage/volunteer_type/form.html.twig', [
            'form' => $form,
            'heading' => 'manage.volunteer_type.form.heading_edit',
            'certifications' => $this->certificationsById(),
            'type' => $volunteerType,
            'members' => $this->memberships->findByVolunteerType($volunteerType),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_volunteer_type_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] VolunteerType $volunteerType): Response
    {
        if ($this->isCsrfTokenValid('delete'.$volunteerType->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($volunteerType);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.volunteer_type.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_volunteer_type_index');
    }
}
