<?php

namespace App\Controller\Backstage;

use App\Entity\GoodieDistribution;
use App\Entity\User;
use App\Repository\GoodieDistributionRepository;
use App\Repository\GoodieItemRepository;
use App\Repository\UserRepository;
use App\Service\GoodieEligibilityService;
use App\Service\HoursCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Goodies distribution workflow: search a user, see their hours and
 * eligible items in three tiers, then hand items over with an audit snapshot
 */
#[Route('/backstage/distribute')]
#[IsGranted('backstage.goodies.agent')]
final class DistributionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly GoodieItemRepository $items,
        private readonly GoodieDistributionRepository $distributions,
        private readonly GoodieEligibilityService $eligibility,
        private readonly HoursCacheService $hoursCache,
    ) {
    }

    #[Route('', name: 'app_backstage_distribute', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        return $this->render('backstage/distribute/search.html.twig', [
            'q' => $q,
            'results' => $q !== '' ? $this->users->search($q) : [],
        ]);
    }

    #[Route('/{id}', name: 'app_backstage_distribute_user', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function user(User $user, Request $request): Response
    {
        $cache = $this->hoursCache->get($user, $request->query->getBoolean('refresh'));

        return $this->render('backstage/distribute/user.html.twig', [
            'user' => $user,
            'cache' => $cache,
            'eligibility' => $this->eligibility->evaluate($user),
            'history' => $this->distributions->findByUser($user),
        ]);
    }

    #[Route('/{id}/refresh', name: 'app_backstage_distribute_refresh', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refresh(Request $request, User $user): Response
    {
        if ($this->isCsrfTokenValid('refresh'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->hoursCache->recalculate($user);
            $this->addFlash('success', 'Hours recalculated.');
        }

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getId()]);
    }

    #[Route('/{id}/give', name: 'app_backstage_distribute_give', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function give(Request $request, User $user): Response
    {
        if ($this->isCsrfTokenValid('give'.$user->getId(), (string) $request->request->get('_token'))) {
            $item = $this->items->find((int) $request->request->get('item'));
            $quantity = max(1, (int) $request->request->get('quantity', 1));

            if ($item === null) {
                $this->addFlash('danger', 'Unknown item.');
            } else {
                $error = $this->eligibility->distributionError($user, $item, $quantity);
                if ($error !== null) {
                    $this->addFlash('danger', $error);
                } else {
                    /** @var User $actor */
                    $actor = $this->getUser();
                    $distribution = new GoodieDistribution($user, $item, $quantity);
                    $distribution->setHoursAtDistribution($this->hoursCache->get($user)->getTotalHours())
                        ->setDistributedBy($actor)
                        ->setNotes((string) $request->request->get('notes') ?: null);
                    $this->em->persist($distribution);
                    $this->em->flush();
                    $this->addFlash('success', \sprintf('Gave %d × %s to %s.', $quantity, $item->getName(), $user->getName()));
                }
            }
        }

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getId()]);
    }
}
