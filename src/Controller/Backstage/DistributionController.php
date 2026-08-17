<?php

namespace App\Controller\Backstage;

use App\Entity\GoodieDistribution;
use App\Entity\State;
use App\Entity\User;
use App\Repository\GoodieDistributionRepository;
use App\Repository\GoodieItemRepository;
use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Certification;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserRepository;
use App\Service\Chat\ConversationService;
use App\Service\ContactMethodResolver;
use App\Service\DigitalIdService;
use App\Service\GoodieEligibilityService;
use App\Service\HoursCacheService;
use App\Service\ProfileAccessService;
use App\Service\ProfilePresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Info-desk "Locate user" workflow: find a volunteer by exact email, registration
 * number, or a scanned badge, then act on them - review hours and hand out goodies,
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
        private readonly AuditLogger $audit,
        private readonly HoursCacheService $hoursCache,
        private readonly DigitalIdService $digitalId,
        private readonly ContactMethodResolver $contacts,
        private readonly ConversationService $conversations,
        private readonly ShiftEntryRepository $shiftEntries,
        private readonly ProfilePresenter $profile,
        private readonly ProfileAccessService $profileAccess,
    ) {
    }

    /**
     * A scanned badge feeds in the digital-id verify URL (or the bare 64-hex token). That token
     * rotates and expires quickly, so failing to resolve one means the badge is stale rather than
     * unknown, and the operator is told so.
     *
     * An exact lookup (email, registration number, resolved badge) lands on a single user and goes
     * straight to that page instead of through a one-row list.
     */
    #[Route('', name: 'app_backstage_distribute', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $results = [];
        $badgeNotResolved = false;

        if ($q !== '') {
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

    /**
     * The identity card repeats what /users/{uuid} shows, so it is gated on the same rule that page
     * enforces, and so is the link to it, which would otherwise offer this operator a 403.
     */
    #[Route('/{id}', name: 'app_backstage_distribute_user', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function user(#[MapEntity(mapping: ['id' => 'uuid'])] User $user, Request $request): Response
    {
        $cache = $this->hoursCache->get($user, $request->query->getBoolean('refresh'));

        $canViewProfile = $this->profileAccess->canView($this->currentUser(), $user);

        return $this->render('backstage/distribute/user.html.twig', [
            'user' => $user,
            'cache' => $cache,
            'canViewProfile' => $canViewProfile,
            'header' => $canViewProfile ? $this->profile->header($user) : null,
            'eligibility' => $this->eligibility->evaluate($user),
            'timeline' => $this->eligibility->timeline($user)['rows'],
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
            $this->addFlash('success', $arrive
                ? new TranslatableMessage('backstage.flash.checked_in', ['%name%' => $user->getName()])
                : new TranslatableMessage('backstage.flash.checkin_removed', ['%name%' => $user->getName()]));
        }

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
    }

    #[Route('/{id}/refresh', name: 'app_backstage_distribute_refresh', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('goodie:distribute')]
    public function refresh(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if ($this->isCsrfTokenValid('refresh'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->hoursCache->recalculate($user);
            $this->addFlash('success', new TranslatableMessage('backstage.flash.hours_recalculated'));
        }

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
    }

    #[Route('/{id}/give', name: 'app_backstage_distribute_give', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('goodie:distribute')]
    public function give(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if ($this->isCsrfTokenValid('give'.$user->getId(), (string) $request->request->get('_token'))) {
            $item = $this->items->findOneByUuid((string) $request->request->get('item'));
            $quantity = max(1, (int) $request->request->get('quantity', 1));

            if ($item === null) {
                $this->addFlash('danger', new TranslatableMessage('backstage.flash.unknown_item'));
            } else {
                $overrideReason = trim((string) $request->request->get('override_reason', ''));
                $missing = $this->eligibility->missingCertifications($user, $item);
                $overriding = $missing !== [] && $overrideReason !== '';

                $error = $overriding
                    ? $this->eligibility->distributionErrorIgnoringCertifications($user, $item, $quantity)
                    : $this->eligibility->distributionError($user, $item, $quantity);

                if ($error !== null) {
                    $this->addFlash('danger', $error);
                } else {
                    /** @var User $actor */
                    $actor = $this->getUser();
                    $distribution = new GoodieDistribution($user, $item, $quantity);
                    $distribution->setHoursAtDistribution($this->hoursCache->get($user)->getTotalHours())
                        ->setDistributedBy($actor)
                        ->setNotes((string) $request->request->get('notes') ?: null)
                        ->setCertificationOverrideReason($overriding ? $overrideReason : null);
                    $this->em->persist($distribution);
                    $this->em->flush();

                    if ($overriding) {
                        $this->audit->log(AuditEvents::CERTIFICATION, AuditEvents::OVERRIDE, [
                            'resourceType' => 'GoodieDistribution',
                            'resourceId' => (string) $user->getUuid(),
                            'details' => [
                                'item' => $item->getName(),
                                'reason' => $overrideReason,
                                'missing_certifications' => array_map(
                                    static fn (Certification $c): string => $c->getTitle(),
                                    $missing,
                                ),
                            ],
                        ]);
                    }
                    $this->addFlash('success', new TranslatableMessage('backstage.flash.gave', [
                        '%count%' => $quantity,
                        '%item%' => $item->getName(),
                        '%name%' => $user->getName(),
                    ]));
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
