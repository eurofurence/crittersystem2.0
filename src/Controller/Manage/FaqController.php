<?php

namespace App\Controller\Manage;

use App\Entity\Faq;
use App\Form\FaqType;
use App\Repository\FaqRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/manage/faq')]
#[IsGranted('faq:manage')]
final class FaqController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FaqRepository $faqs,
    ) {
    }

    #[Route('', name: 'app_manage_faq_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('manage/faq/index.html.twig', [
            'faqs' => $this->faqs->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_manage_faq_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $faq = new Faq();
        $form = $this->createForm(FaqType::class, $faq);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($faq);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.faq.flash.created'));

            return $this->redirectToRoute('app_manage_faq_index');
        }

        return $this->render('manage/faq/form.html.twig', ['form' => $form, 'heading' => 'manage.faq.form.heading_new']);
    }

    #[Route('/{id}/edit', name: 'app_manage_faq_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Faq $faq): Response
    {
        $form = $this->createForm(FaqType::class, $faq);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.faq.flash.updated'));

            return $this->redirectToRoute('app_manage_faq_index');
        }

        return $this->render('manage/faq/form.html.twig', ['form' => $form, 'heading' => 'manage.faq.form.heading_edit']);
    }

    #[Route('/{id}/delete', name: 'app_manage_faq_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] Faq $faq): Response
    {
        if ($this->isCsrfTokenValid('delete'.$faq->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($faq);
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('manage.faq.flash.deleted'));
        }

        return $this->redirectToRoute('app_manage_faq_index');
    }
}
