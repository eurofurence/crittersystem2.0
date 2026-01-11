<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DataKitController extends AbstractController
{
#[Route('/dev/ui/data-kit', name: 'app_data_kit')]
    public function index(): Response
    {
        // Dummy data: simulate a list page (e.g., departments, shifts, users)
        $rows = [
            ['id' => 1, 'name' => 'Logistics', 'status' => 'Active', 'members' => 42],
            ['id' => 2, 'name' => 'Registration', 'status' => 'Pending', 'members' => 18],
            ['id' => 3, 'name' => 'Security', 'status' => 'Active', 'members' => 12],
        ];

        return $this->render('data_kit/index.html.twig', [
            'pageTitle' => 'Data Components (CS-002) Demo',
            'rows' => $rows,
        ]);
    }

    #[Route('/dev/ui/data-kit/table-frame', name: 'app_data_kit_table_frame')]
    public function tableFrame(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        $rows = [
            ['id' => 1, 'name' => 'Logistics', 'status' => 'Active', 'members' => 42],
            ['id' => 2, 'name' => 'Registration', 'status' => 'Pending', 'members' => 18],
            ['id' => 3, 'name' => 'Security', 'status' => 'Active', 'members' => 12],
            ['id' => 4, 'name' => 'IT', 'status' => 'Disabled', 'members' => 5],
        ];

        if ($q !== '') {
            $rows = array_values(array_filter($rows, static fn(array $r) => str_contains(mb_strtolower($r['name']), mb_strtolower($q))));
        }

        return $this->render('data_kit/_table_frame.html.twig', [
            'q' => $q,
            'rows' => $rows,
        ]);
    }
}
