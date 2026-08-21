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
            'history' => $this->distributions->findByUser($user, 50, true),
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

    /**
     * Hand over several items in one submission, so the desk ticks a row of checkboxes instead of
     * posting once per goodie and losing its place on the page each time.
     *
     * The row buttons submit the same form with `only` set, which keeps one endpoint and one set of
     * eligibility rules behind both paths. Certification requirements are never waived here: an item
     * the recipient is not qualified for is refused and stays in the blocked pane, where overriding
     * it is a deliberate, audit-logged act.
     */
    #[Route('/{id}/give-bulk', name: 'app_backstage_distribute_give_bulk', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('goodie:distribute')]
    public function giveBulk(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if (!$this->isCsrfTokenValid('give-bulk'.$user->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
        }

        $only = trim((string) $request->request->get('only', ''));
        $selected = $this->uuidList($only !== '' ? [$only] : $request->request->all('items'));

        if ($selected === []) {
            $this->addFlash('warning', new TranslatableMessage('backstage.flash.nothing_selected'));

            return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
        }

        $quantities = $request->request->all('quantities');
        $notes = trim((string) $request->request->get('notes', '')) ?: null;
        $hours = $this->hoursCache->get($user)->getTotalHours();
        /** @var User $actor */
        $actor = $this->getUser();

        $given = 0;
        foreach ($selected as $uuid) {
            $item = $this->items->findOneByUuid($uuid);
            if ($item === null) {
                $this->addFlash('danger', new TranslatableMessage('backstage.flash.unknown_item'));
                continue;
            }

            $quantity = max(1, $this->quantityFor($quantities, $uuid, 1));
            $error = $this->eligibility->distributionError($user, $item, $quantity);
            if ($error !== null) {
                $this->addFlash('danger', new TranslatableMessage('backstage.flash.give_failed', [
                    '%item%' => $item->getName(),
                    '%reason%' => $error,
                ]));
                continue;
            }

            $distribution = new GoodieDistribution($user, $item, $quantity);
            $distribution->setHoursAtDistribution($hours)
                ->setDistributedBy($actor)
                ->setNotes($notes);
            $this->em->persist($distribution);
            ++$given;
        }

        if ($given > 0) {
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('backstage.flash.gave_bulk', [
                '%count%' => $given,
                '%name%' => $user->getName(),
            ]));
        }

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
    }

    /**
     * Undo one or more handovers the desk recorded in error.
     *
     * The record survives: revoking marks it, names the actor and takes the quantity out of every
     * count, which puts the item back within reach. A row belonging to somebody else is answered
     * with a 404 rather than a 403, so this endpoint cannot be used to confirm that a distribution
     * exists for a volunteer the caller did not open.
     */
    #[Route('/{id}/revoke', name: 'app_backstage_distribute_revoke', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('goodie:distribute')]
    public function revoke(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if (!$this->isCsrfTokenValid('revoke'.$user->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
        }

        $single = trim((string) $request->request->get('distribution', ''));
        $selected = $this->uuidList($single !== '' ? [$single] : $request->request->all('distributions'));

        if ($selected === []) {
            $this->addFlash('warning', new TranslatableMessage('backstage.flash.nothing_selected'));

            return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
        }

        $reason = trim((string) $request->request->get('reason', '')) ?: null;
        /** @var User $actor */
        $actor = $this->getUser();

        $revoked = 0;
        foreach ($selected as $uuid) {
            $distribution = $this->ownedDistribution($uuid, $user);
            if ($distribution->isRevoked()) {
                continue;
            }

            $distribution->revoke($actor, $reason);
            ++$revoked;

            $this->audit->log(AuditEvents::GOODIE, AuditEvents::REVOKE, [
                'resourceType' => 'GoodieDistribution',
                'resourceId' => (string) $distribution->getUuid(),
                'resourceOwnerId' => $user->getId(),
                'details' => [
                    'item' => $distribution->getItemName(),
                    'quantity' => $distribution->getQuantity(),
                    'reason' => $reason,
                ],
            ]);
        }

        if ($revoked > 0) {
            $this->em->flush();
            $this->addFlash('success', new TranslatableMessage('backstage.flash.revoked', ['%count%' => $revoked]));
        } else {
            $this->addFlash('warning', new TranslatableMessage('backstage.flash.already_revoked'));
        }

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
    }

    /**
     * Amend how many of an item were really handed over, for the case where the desk recorded the
     * wrong number rather than the wrong item. Dropping to nothing is a revoke, not a correction,
     * and the per-person limit still applies to what the amended row would leave behind.
     *
     * The quantity fields live inside the revoke form and are bound to this action by their `form`
     * attribute, so both actions are reached from the one rendered token.
     */
    #[Route('/{id}/correct', name: 'app_backstage_distribute_correct', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('goodie:distribute')]
    public function correct(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if (!$this->isCsrfTokenValid('revoke'.$user->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
        }

        $distribution = $this->ownedDistribution(trim((string) $request->request->get('distribution', '')), $user);
        $quantity = $this->quantityFor($request->request->all('quantities'), (string) $distribution->getUuid(), 0);

        if ($distribution->isRevoked()) {
            $this->addFlash('warning', new TranslatableMessage('backstage.flash.already_revoked'));

            return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
        }

        $error = $this->correctionError($distribution, $user, $quantity);
        if ($error !== null) {
            $this->addFlash('danger', $error);

            return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
        }

        if ($quantity !== $distribution->getQuantity()) {
            $previous = $distribution->getQuantity();
            /** @var User $actor */
            $actor = $this->getUser();
            $distribution->correctQuantity($quantity, $actor, trim((string) $request->request->get('reason', '')) ?: null);
            $this->em->flush();

            $this->audit->log(AuditEvents::GOODIE, AuditEvents::UPDATE, [
                'resourceType' => 'GoodieDistribution',
                'resourceId' => (string) $distribution->getUuid(),
                'resourceOwnerId' => $user->getId(),
                'details' => [
                    'item' => $distribution->getItemName(),
                    'from' => $previous,
                    'to' => $quantity,
                ],
            ]);

            $this->addFlash('success', new TranslatableMessage('backstage.flash.corrected', [
                '%item%' => $distribution->getItemName(),
                '%count%' => $quantity,
            ]));
        }

        return $this->redirectToRoute('app_backstage_distribute_user', ['id' => $user->getUuid()]);
    }

    private function correctionError(GoodieDistribution $distribution, User $user, int $quantity): ?TranslatableMessage
    {
        if ($quantity < 1) {
            return new TranslatableMessage('backstage.flash.correct_min_quantity');
        }

        $item = $distribution->getItem();
        $max = $item?->getMaxPerPerson();
        if ($item !== null && $max !== null) {
            $others = $this->distributions->quantityForUserAndItem($user, $item) - $distribution->getQuantity();
            if ($others + $quantity > $max) {
                return new TranslatableMessage('backstage.flash.correct_over_limit', [
                    '%max%' => $max,
                    '%item%' => $item->getName(),
                ]);
            }
        }

        return null;
    }

    /**
     * The identifiers a bulk submission selected, keeping only what could be one: the checkbox
     * values are attacker-controlled and a nested payload would otherwise reach a string cast as an
     * array.
     *
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function uuidList(array $values): array
    {
        $uuids = [];
        foreach ($values as $value) {
            if (\is_string($value) && trim($value) !== '') {
                $uuids[] = trim($value);
            }
        }

        return array_values(array_unique($uuids));
    }

    /**
     * A blank number box posts an empty string, which is present and so never falls back to the
     * default; anything that is not a number at all counts as absent.
     *
     * @param array<mixed> $quantities
     */
    private function quantityFor(array $quantities, string $key, int $default): int
    {
        $value = $quantities[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * A distribution addressed through another volunteer's page is treated as missing, so the
     * endpoint never confirms which handovers exist for somebody the caller is not looking at.
     */
    private function ownedDistribution(string $uuid, User $user): GoodieDistribution
    {
        $distribution = $uuid !== '' ? $this->distributions->findOneByUuid($uuid) : null;
        if ($distribution === null || $distribution->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        return $distribution;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
