<?php

namespace App\Controller\Backstage;

use App\Entity\GoodieItem;
use App\Form\GoodieItemType;
use App\Repository\GoodieCategoryRepository;
use App\Repository\GoodieItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backstage/goodies/items')]
#[IsGranted('goodie:manage')]
final class GoodieItemController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GoodieItemRepository $items,
        private readonly GoodieCategoryRepository $categories,
    ) {
    }

    #[Route('', name: 'app_backstage_goodie_item_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('backstage/goodie_item/index.html.twig', [
            'items' => $this->items->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_backstage_goodie_item_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $category = $this->categories->findOneBy([]);
        if ($category === null) {
            $this->addFlash('warning', 'Create a goodie category before adding items.');

            return $this->redirectToRoute('app_backstage_goodie_category_new');
        }

        $item = new GoodieItem($category, '');
        $form = $this->createForm(GoodieItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($item);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Item "%s" created.', $item->getName()));

            return $this->redirectToRoute('app_backstage_goodie_item_index');
        }

        return $this->render('backstage/goodie_item/form.html.twig', [
            'form' => $form,
            'heading' => 'New goodie item',
        ]);
    }

    #[Route('/{id}/edit', name: 'app_backstage_goodie_item_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] GoodieItem $item): Response
    {
        $form = $this->createForm(GoodieItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', \sprintf('Item "%s" updated.', $item->getName()));

            return $this->redirectToRoute('app_backstage_goodie_item_index');
        }

        return $this->render('backstage/goodie_item/form.html.twig', [
            'form' => $form,
            'heading' => 'Edit goodie item',
        ]);
    }

    #[Route('/{id}/delete', name: 'app_backstage_goodie_item_delete', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] GoodieItem $item): Response
    {
        if ($this->isCsrfTokenValid('delete'.$item->getId(), (string) $request->request->get('_token'))) {
            $this->em->remove($item);
            $this->em->flush();
            $this->addFlash('success', 'Item deleted.');
        }

        return $this->redirectToRoute('app_backstage_goodie_item_index');
    }
}
