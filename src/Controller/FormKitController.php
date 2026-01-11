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

    #[Route('/dev/kit/search-frame', name: 'app_form_kit_search_frame')]
    public function searchFrame(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        $items = [
            'Logistics',
            'Registration',
            'Ops',
            'Security',
            'IT',
            'Stage',
            'Communications',
            'Sponsors',
        ];

        if ($q !== '') {
            $items = array_values(array_filter($items, static fn (string $v) => str_contains(mb_strtolower($v), mb_strtolower($q))));
        }

        return $this->render('form_kit/_search_frame.html.twig', [
            'q' => $q,
            'items' => $items,
        ]);
    }
}
