<?php

namespace App\Controller\Manage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ManageController extends AbstractController
{
    #[Route('/manage', name: 'app_manage')]
    public function index(): Response
    {
        return $this->render('manage/index.html.twig');
    }
}
