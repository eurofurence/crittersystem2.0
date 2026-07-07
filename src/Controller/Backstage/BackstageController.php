<?php

namespace App\Controller\Backstage;

use App\Repository\GoodieDistributionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backstage')]
#[IsGranted('backstage:view')]
final class BackstageController extends AbstractController
{
    public function __construct(private readonly GoodieDistributionRepository $distributions)
    {
    }

    #[Route('', name: 'app_backstage', methods: ['GET'])]
    public function dashboard(): Response
    {
        $today = new \DateTimeImmutable('today');

        return $this->render('backstage/dashboard.html.twig', [
            'recent' => $this->distributions->findRecent(10),
            'distributedToday' => $this->distributions->countSince($today),
            'distributedThisWeek' => $this->distributions->countSince($today->modify('-7 days')),
        ]);
    }
}
