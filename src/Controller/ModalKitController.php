<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ModalKitController extends AbstractController
{
    #[Route('/dev/ui/modal-kit', name: 'app_modal_kit')]
    public function index(): Response
    {
        return $this->render('modal_kit/index.html.twig', [
            'pageTitle' => 'Modal Components (CS-003) Demo',
        ]);
    }
}
