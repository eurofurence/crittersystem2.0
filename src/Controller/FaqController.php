<?php

namespace App\Controller;

use App\Repository\FaqRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public FAQ, grouped by category
 */
#[Route('/faq')]
#[IsGranted('faq.view')]
final class FaqController extends AbstractController
{
    #[Route('', name: 'app_faq_index', methods: ['GET'])]
    public function index(FaqRepository $faqs): Response
    {
        $byCategory = [];
        foreach ($faqs->findAllOrdered() as $faq) {
            $byCategory[$faq->getCategory()][] = $faq;
        }

        return $this->render('faq/index.html.twig', ['byCategory' => $byCategory]);
    }
}
