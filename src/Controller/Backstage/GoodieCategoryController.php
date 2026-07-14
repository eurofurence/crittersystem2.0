<?php

namespace App\Controller\Backstage;

use App\Entity\GoodieCategory;
use App\Form\GoodieCategoryType;
use App\Repository\GoodieCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backstage/goodies/categories')]
#[IsGranted('goodie:manage')]
final class GoodieCategoryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GoodieCategoryRepository $categories,
    ) {
    }

    #[Route('', name: 'app_backstage_goodie_category_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('backstage/goodie_category/index.html.twig', [
            'categories' => $this->categories->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_backstage_goodie_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $category = new GoodieCategory('');
        $form = $this->createForm(GoodieCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($category);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Category "%s" created.', $category->getName()));

            return $this->redirectToRoute('app_backstage_goodie_category_index');
        }

        return $this->render('backstage/goodie_category/form.html.twig', [
            'form' => $form,
            'heading' => 'New goodie category',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_backstage_goodie_category_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] GoodieCategory $category): Response
    {
        $form = $this->createForm(GoodieCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', \sprintf('Category "%s" updated.', $category->getName()));

            return $this->redirectToRoute('app_backstage_goodie_category_index');
        }

        return $this->render('backstage/goodie_category/form.html.twig', [
            'form' => $form,
            'heading' => 'Edit goodie category',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_backstage_goodie_category_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] GoodieCategory $category): Response
    {
        if ($this->isCsrfTokenValid('delete'.$category->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($category);
            $this->em->flush();
            $this->addFlash('success', 'Category deleted.');
        }

        return $this->redirectToRoute('app_backstage_goodie_category_index');
    }
}
