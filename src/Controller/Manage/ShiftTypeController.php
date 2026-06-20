<?php

namespace App\Controller\Manage;

use App\Entity\ShiftType;
use App\Form\ShiftTypeType;
use App\Repository\ShiftTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/shift-types')]
#[IsGranted('admin_shifts')]
final class ShiftTypeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftTypeRepository $shiftTypes,
    ) {
    }

    #[Route('', name: 'app_manage_shift_type_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/shift_type/index.html.twig', [
            'shiftTypes' => $this->shiftTypes->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_manage_shift_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $shiftType = new ShiftType('');
        $form = $this->createForm(ShiftTypeType::class, $shiftType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($shiftType);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Shift type "%s" created.', $shiftType->getName()));

            return $this->redirectToRoute('app_manage_shift_type_index');
        }

        return $this->render('manage/shift_type/form.html.twig', [
            'form' => $form,
            'heading' => 'New shift type',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_shift_type_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ShiftType $shiftType): Response
    {
        $form = $this->createForm(ShiftTypeType::class, $shiftType);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', \sprintf('Shift type "%s" updated.', $shiftType->getName()));

            return $this->redirectToRoute('app_manage_shift_type_index');
        }

        return $this->render('manage/shift_type/form.html.twig', [
            'form' => $form,
            'heading' => 'Edit shift type',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_shift_type_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ShiftType $shiftType): Response
    {
        if ($this->isCsrfTokenValid('delete'.$shiftType->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($shiftType);
            $this->em->flush();
            $this->addFlash('success', 'Shift type deleted.');
        }

        return $this->redirectToRoute('app_manage_shift_type_index');
    }
}
