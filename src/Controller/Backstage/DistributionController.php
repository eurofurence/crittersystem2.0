<?php

namespace App\Controller\Backstage;

use App\Entity\GoodieDistribution;
use App\Entity\State;
use App\Entity\User;
use App\Repository\GoodieDistributionRepository;
use App\Repository\GoodieItemRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserRepository;
use App\Service\Chat\ConversationService;
use App\Service\ContactMethodResolver;
use App\Service\DigitalIdService;
use App\Service\GoodieEligibilityService;
use App\Service\HoursCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Info-desk "Locate user" workflow: find a volunteer by exact email, registration
 * number, or a scanned badge, then act on them — review hours and hand out goodies,
 * check them in, look at their shifts, and reach them like a department manager can.
 */
#[Route('/backstage/distribute')]
#[IsGranted('user:locate')]
final class DistributionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly GoodieItemRepository $items,
        private readonly GoodieDistributionRepository $distributions,
        private readonly GoodieEligibilityService $eligibility,
        private readonly HoursCacheService $hoursCache,
        private readonly DigitalIdService $digitalId,
        private readonly ContactMethodResolver $contacts,
        private readonly ConversationService $conversations,
        private readonly ShiftEntryRepository $shiftEntries,
    ) {
    }

    #[Route('', name: 'app_backstage_distribute', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $results = [];
        $badgeNotResolved = false;

        if ($q !== '') {
            // A scanned badge feeds in the digital-id verify URL (or the bare 64-hex token).
            // The token rotates and expires quickly, so a miss here means the badge is stale.
            if (preg_match('/[0-9a-f]{64}/', $q, $m)) {
                $token = $this->digitalId->findActive($m[0]);
                if ($token !== null) {
                    $results = [$token->getUser()];
                } else {
                    $badgeNotResolved = true;
                }
            } else {
                $results = $this->users->locate($q);
            }

            // Exact lookups (email, registration number, resolved badge) land on a single user;
            // send the operator straight there instead of through a one-row list.
            if (\count($results) === 1) {
                return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $results[0]->getUuid()]);
            }
        }

        return $this->render('backstage/distribute/search.html.twig', [
            'q' => $q,
            'results' => $results,
            'badgeNotResolved' => $badgeNotResolved,
        ]);
    }

    #[Route('/{id}', name: 'app_backstage_distribute_user', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function user(#[MapEntity(mapping: ['id' => 'uuid'])] User $user, Request $request): Response
    {
        $cache = $this->hoursCache->get($user, $request->query->getBoolean('refresh'));

        return $this->render('backstage/distribute/user.html.twig', [
            'user' => $user,
            'cache' => $cache,
            'eligibility' => $this->eligibility->evaluate($user),
            'history' => $this->distributions->findByUser($user),
            'shifts' => $this->shiftEntries->findByUserOrdered($user),
            'contactMethods' => $this->contacts->methodsFor($user),
            'canDirectMessage' => $this->conversations->enabled() && $this->conversations->canInitiateDirect($this->currentUser()),
        ]);
    }

    #[Route('/{id}/checkin', name: 'app_backstage_distribute_checkin', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('user:arrive')]
    public function checkin(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if ($this->isCsrfTokenValid('checkin'.$user->getId(), (string) $request->request->get('_token'))) {
            $arrive = $request->request->getBoolean('arrived');
            $state = $user->getState() ?? new State($user);
            $state->setArrived($arrive)->setArrivalDate($arrive ? new \DateTimeImmutable() : null);
            $user->setState($state);
            $this->em->persist($state);
            $this->em->flush();
            $this->addFlash('success', $arrive ? $user->getName().' checked in.' : 'Check-in removed for '.$user->getName().'.');
        }

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
    }

    #[Route('/{id}/refresh', name: 'app_backstage_distribute_refresh', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('goodie:distribute')]
    public function refresh(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if ($this->isCsrfTokenValid('refresh'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->hoursCache->recalculate($user);
            $this->addFlash('success', 'Hours recalculated.');
        }

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
    }

    #[Route('/{id}/give', name: 'app_backstage_distribute_give', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('goodie:distribute')]
    public function give(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
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

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
