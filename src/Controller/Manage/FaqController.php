<?php

namespace App\Controller\Manage;

use App\Entity\Faq;
use App\Form\FaqType;
use App\Repository\FaqRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/faq')]
#[IsGranted('faq.edit')]
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
            $this->addFlash('success', 'FAQ entry created.');

            return $this->redirectToRoute('app_manage_faq_index');
        }

        return $this->render('manage/faq/form.html.twig', ['form' => $form, 'heading' => 'New FAQ entry']);
    }

    #[Route('/{id}/edit', name: 'app_manage_faq_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Faq $faq): Response
    {
        $form = $this->createForm(FaqType::class, $faq);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'FAQ entry updated.');

            return $this->redirectToRoute('app_manage_faq_index');
        }

        return $this->render('manage/faq/form.html.twig', ['form' => $form, 'heading' => 'Edit FAQ entry']);
    }

    #[Route('/{id}/delete', name: 'app_manage_faq_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Faq $faq): Response
    {
        if ($this->isCsrfTokenValid('delete'.$faq->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($faq);
            $this->em->flush();
            $this->addFlash('success', 'FAQ entry deleted.');
        }

        return $this->redirectToRoute('app_manage_faq_index');
    }
}
