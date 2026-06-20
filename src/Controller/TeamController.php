<?php

namespace App\Controller;

use App\Service\StaffStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin team status dashboard: a roster with each member's duty
 * area, hours and goodies, plus a CSV export.
 */
#[Route('/staff/team')]
#[IsGranted('user.type.internal_staff')]
final class TeamController extends AbstractController
{
    public function __construct(private readonly StaffStatsService $stats)
    {
    }

    #[Route('', name: 'app_staff_team', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('staff/team.html.twig', [
            'rows' => $this->stats->teamStats(),
        ]);
    }

    #[Route('/export.csv', name: 'app_staff_team_export', methods: ['GET'])]
    public function export(): Response
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Name', 'Email', 'On duty', 'Duty hours', 'Shift hours', 'Goodies']);
        foreach ($this->stats->teamStats() as $row) {
            fputcsv($handle, [
                $row['user']->getName(),
                $row['user']->getEmail(),
                $row['currentDuty'] !== null ? ($row['currentDuty']->getDepartment()?->getName() ?? 'yes') : 'no',
                number_format($row['dutyHours'], 2),
                number_format($row['shiftHours'], 2),
                $row['goodies'],
            ]);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="team-status.csv"',
        ]);
    }
}
