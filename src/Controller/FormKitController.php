<?php

namespace App\Controller;

use App\Form\FormKitDemoType;
use App\Form\Model\FormKitDemoData;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormKitController extends AbstractController
{
    #[Route('/dev/kit', name: 'app_form_kit')]
    public function index(Request $request): Response
    {
        $data = new FormKitDemoData();

        $form = $this->createForm(FormKitDemoType::class, $data);
        $form->handleRequest($request);

        $submitted = false;
        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = true;
        }

        return $this->render('form_kit/index.html.twig', [
            'pageTitle' => 'Form Kit (CS-001) Demo',
            'form' => $form,
            'submitted' => $submitted,
            'data' => $data,
        ]);

        // return $this->render('form_kit/index.html.twig', [
        //     'controller_name' => 'FormKitController',
        // ]);
    }
}
