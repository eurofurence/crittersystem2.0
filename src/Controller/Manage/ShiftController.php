<?php

namespace App\Controller\Manage;

use App\Entity\Shift;
use App\Entity\User;
use App\Form\ShiftFormType;
use App\Repository\ShiftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/shifts')]
#[IsGranted('admin_shifts')]
final class ShiftController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftRepository $shifts,
    ) {
    }

    #[Route('', name: 'app_manage_shift_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/shift/index.html.twig', [
            'shifts' => $this->shifts->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_manage_shift_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $shift = new Shift();
        $form = $this->createForm(ShiftFormType::class, $shift);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();
            $shift->setCreatedBy($user)->setUpdatedBy($user);
            $this->em->persist($shift);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Shift "%s" created.', $shift->getTitle()));

            return $this->redirectToRoute('app_manage_shift_index');
        }

        return $this->render('manage/shift/form.html.twig', [
            'form' => $form,
            'heading' => 'New shift',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_manage_shift_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Shift $shift): Response
    {
        $form = $this->createForm(ShiftFormType::class, $shift);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();
            $shift->setUpdatedBy($user);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Shift "%s" updated.', $shift->getTitle()));

            return $this->redirectToRoute('app_manage_shift_index');
        }

        return $this->render('manage/shift/form.html.twig', [
            'form' => $form,
            'heading' => 'Edit shift',
            'shift' => $shift,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_manage_shift_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Shift $shift): Response
    {
        if ($this->isCsrfTokenValid('delete'.$shift->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($shift);
            $this->em->flush();
            $this->addFlash('success', 'Shift deleted.');
        }

        return $this->redirectToRoute('app_manage_shift_index');
    }
}
