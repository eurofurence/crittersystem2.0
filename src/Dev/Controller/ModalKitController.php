<?php

namespace App\Dev\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('global:admin')]
final class ModalKitController extends AbstractController
{
    #[Route('/dev/ui/modal-kit', name: 'app_modal_kit')]
    public function index(): Response
    {
        return $this->render('modal_kit/index.html.twig', [
            'pageTitle' => 'UI Kit - Modals',
        ]);
    }
}
