<?php

namespace App\Dev\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The UI gallery for templates/components/data/_macros.twig.
 *
 * Every row of data below is deliberately, obviously fake ("Demo Department …",
 * "Sample Volunteer", "@demo.invalid"). Nothing here reads from the database.
 */
#[IsGranted('global:admin')]
final class DataKitController extends AbstractController
{
    /** @var list<array{id:int,name:string,status:string,members:int,lead:string,email:string}> */
    private const DEMO_ROWS = [
        ['id' => 101, 'name' => 'Demo Department Alpha', 'status' => 'Active', 'members' => 42, 'lead' => 'Sample Volunteer', 'email' => 'alpha@demo.invalid'],
        ['id' => 102, 'name' => 'Demo Department Bravo', 'status' => 'Pending', 'members' => 18, 'lead' => 'Example Person', 'email' => 'bravo@demo.invalid'],
        ['id' => 103, 'name' => 'Demo Department Charlie', 'status' => 'Disabled', 'members' => 0, 'lead' => 'Test Dummy', 'email' => 'charlie@demo.invalid'],
        ['id' => 104, 'name' => 'Demo Department Delta with a deliberately very long name so that we can see how a cell wraps or overflows', 'status' => 'Archived', 'members' => 7, 'lead' => 'Sample Volunteer', 'email' => 'delta@demo.invalid'],
    ];

    #[Route('/dev/ui/data-kit', name: 'app_data_kit')]
    public function index(): Response
    {
        return $this->render('data_kit/index.html.twig', [
            'pageTitle' => 'Data & layout components',
            'rows' => self::DEMO_ROWS,
            'wideColumns' => [
                'ID', 'Name', 'Status', 'Members', 'Lead', 'Email', 'Location', 'Created', 'Updated', 'Shifts', 'Hours', 'Rating', 'Notes',
            ],
            'wideRows' => array_map(static fn (array $r): array => [
                $r['id'],
                $r['name'],
                $r['status'],
                (string) $r['members'],
                $r['lead'],
                $r['email'],
                'Demo Venue, Hall Z',
                '2026-01-01',
                '2026-01-02',
                '12',
                '96',
                '4.5',
                'Fake note for the horizontal-scroll check',
            ], self::DEMO_ROWS),
        ]);
    }

    #[Route('/dev/ui/data-kit/table-frame', name: 'app_data_kit_table_frame')]
    public function tableFrame(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        $rows = self::DEMO_ROWS;

        if ($q !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => str_contains(mb_strtolower($r['name']), mb_strtolower($q)),
            ));
        }

        return $this->render('data_kit/_table_frame.html.twig', [
            'q' => $q,
            'rows' => $rows,
        ]);
    }

    /**
     * The target of the demo delete_form(). It deletes nothing — it only proves the CSRF
     * token and the `confirm` Stimulus controller are wired up correctly.
     */
    #[Route('/dev/ui/data-kit/delete', name: 'app_data_kit_delete', methods: ['POST'])]
    public function fakeDelete(Request $request): Response
    {
        $token = (string) $request->request->get('_token');

        $this->addFlash(
            $this->isCsrfTokenValid('data_kit_demo', $token) ? 'success' : 'danger',
            $this->isCsrfTokenValid('data_kit_demo', $token)
                ? 'Demo delete submitted (CSRF token valid). Nothing was deleted — this is a gallery.'
                : 'Demo delete rejected: invalid CSRF token.',
        );

        return $this->redirectToRoute('app_data_kit');
    }
}
