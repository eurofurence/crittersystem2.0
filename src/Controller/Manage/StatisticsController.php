<?php

namespace App\Controller\Manage;

use App\Form\Model\StatisticsTalliesData;
use App\Form\StatisticsTalliesType;
use App\Service\Statistics\EventStatisticsService;
use App\Service\Statistics\FunFactBuilder;
use App\Service\Statistics\TallyStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Event statistics for the closing ceremony.
 *
 * Read-only and event-wide, so there is nothing to scope per department and no live updates: the
 * figures are computed on request and the page carries a refresh link rather than a timer. An event
 * total that moved on screen mid-sentence would be worse than one that is a minute old.
 */
#[Route('/manage/statistics')]
#[IsGranted('global:dashboard')]
final class StatisticsController extends AbstractController
{
    public function __construct(
        private readonly EventStatisticsService $statistics,
        private readonly FunFactBuilder $funFacts,
        private readonly TallyStore $tallies,
    ) {
    }

    #[Route('', name: 'app_manage_statistics', methods: ['GET'])]
    public function index(): Response
    {
        $stats = $this->statistics->compute();
        $tallies = $this->tallies->load();

        return $this->render('manage/statistics/index.html.twig', [
            'stats' => $stats,
            'derived' => $this->funFacts->derived($stats),
            'tallyFacts' => $this->funFacts->tallies($tallies, $stats),
            'hasTallies' => !$tallies->isEmpty(),
        ]);
    }

    #[Route('/tallies', name: 'app_manage_statistics_tallies', methods: ['GET', 'POST'])]
    public function tallies(Request $request): Response
    {
        $data = StatisticsTalliesData::fromTallies($this->tallies->load());

        $form = $this->createForm(StatisticsTalliesType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->tallies->save($data->toTallies());
            $this->addFlash('success', new TranslatableMessage('manage.statistics.tallies.flash.saved'));

            return $this->redirectToRoute('app_manage_statistics');
        }

        return $this->render('manage/statistics/tallies.html.twig', ['form' => $form]);
    }
}
