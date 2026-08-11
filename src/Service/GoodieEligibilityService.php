<?php

namespace App\Service;

use App\Entity\Certification;
use App\Entity\GoodieItem;
use App\Entity\User;
use App\Repository\GoodieDistributionRepository;
use App\Repository\GoodieItemRepository;
use App\Repository\UserCertificationRepository;

/**
 * Decides which goodie items a user can claim, from their cached hours, their certifications and
 * what they have already been given.
 */
final class GoodieEligibilityService
{
    public function __construct(
        private readonly HoursCacheService $hoursCache,
        private readonly GoodieItemRepository $items,
        private readonly GoodieDistributionRepository $distributions,
        private readonly UserCertificationRepository $certifications,
    ) {
    }

    /**
     * Active certifications the item requires and the user does not validly hold.
     *
     * Held means {@see \App\Entity\UserCertification::isValid()} - approved or self-confirmed and
     * not expired - the same predicate that decides whether somebody may take a shift needing the
     * certification, so the two surfaces cannot disagree about who is qualified.
     *
     * A deactivated certification is skipped rather than blocking. Deactivating one is how a
     * requirement is retired, and the link is kept so reactivating restores it.
     *
     * @return list<Certification>
     */
    public function missingCertifications(User $user, GoodieItem $item): array
    {
        $required = [];
        foreach ($item->getCertifications() as $certification) {
            if ($certification->isActive()) {
                $required[] = $certification;
            }
        }
        if ($required === []) {
            return [];
        }

        $held = [];
        foreach ($this->certifications->findByUser($user) as $record) {
            if ($record->isValid()) {
                $held[$record->getCertification()->getId()] = true;
            }
        }

        return array_values(array_filter(
            $required,
            static fn (Certification $certification): bool => !isset($held[$certification->getId()]),
        ));
    }

    /**
     * Evaluate all active items for a user into four tiers: eligible (claim now), pending (hours
     * gap), claimed (at per-person max), blocked (missing a required certification).
     *
     * Blocked outranks pending: an item the volunteer cannot claim however many hours they work
     * should not be advertised to them as a few hours away.
     *
     * @return array{hours: float, rows: list<array{item: GoodieItem, tier: string, claimed: int, gap: float, remaining: ?int, missingCertifications: list<Certification>}>}
     */
    public function evaluate(User $user): array
    {
        $hours = $this->hoursCache->get($user)->getTotalHours();

        $rows = [];
        foreach ($this->items->findActiveForDistribution() as $item) {
            $claimed = $this->distributions->quantityForUserAndItem($user, $item);
            $atMax = $item->getMaxPerPerson() !== null && $claimed >= $item->getMaxPerPerson();
            $meetsHours = $hours >= $item->getRequiredHours();
            $missing = $this->missingCertifications($user, $item);

            if ($atMax) {
                $tier = 'claimed';
            } elseif ($missing !== []) {
                $tier = 'blocked';
            } else {
                $tier = $meetsHours ? 'eligible' : 'pending';
            }

            $rows[] = [
                'item' => $item,
                'tier' => $tier,
                'claimed' => $claimed,
                'gap' => max(0.0, $item->getRequiredHours() - $hours),
                'remaining' => $item->getMaxPerPerson() !== null ? $item->getMaxPerPerson() - $claimed : null,
                'missingCertifications' => $missing,
            ];
        }

        return ['hours' => $hours, 'rows' => $rows];
    }

    /**
     * The same items as {@see evaluate()}, re-sorted into a single ladder by required hours and each
     * tagged with the marker its progress timeline draws.
     *
     * The sort is the point: {@see \App\Repository\GoodieItemRepository::findActiveForDistribution()}
     * orders by category first, which is what the hand-out tables want and would make a ladder run
     * backwards wherever a category boundary falls.
     *
     * Exactly one row is marked `next` - the cheapest item the volunteer has not reached yet - and it
     * is the marker the whole display hangs on. `blocked` outranks it, so an item no amount of work
     * can unlock is never held out as the goal.
     *
     * @return array{hours: float, rows: list<array{item: GoodieItem, tier: string, marker: string, claimed: int, gap: float, remaining: ?int, missingCertifications: list<Certification>}>}
     */
    public function timeline(User $user): array
    {
        $evaluation = $this->evaluate($user);
        $rows = $evaluation['rows'];

        usort($rows, static fn (array $a, array $b): int => [$a['item']->getRequiredHours(), $a['item']->getName()]
            <=> [$b['item']->getRequiredHours(), $b['item']->getName()]);

        $nextTaken = false;
        foreach ($rows as $index => $row) {
            $marker = match ($row['tier']) {
                'claimed' => 'collected',
                'eligible' => 'available',
                'blocked' => 'blocked',
                default => $nextTaken ? 'locked' : 'next',
            };
            $nextTaken = $nextTaken || $marker === 'next';
            $rows[$index]['marker'] = $marker;
        }

        return ['hours' => $evaluation['hours'], 'rows' => $rows];
    }

    /**
     * Reason the item cannot be given to the user in the requested quantity, or null when the
     * distribution is allowed.
     */
    public function distributionError(User $user, GoodieItem $item, int $quantity): ?string
    {
        $missing = $this->missingCertifications($user, $item);
        if ($missing !== []) {
            return \sprintf('Missing certifications: %s.', implode(', ', array_map(
                static fn (Certification $c): string => $c->getTitle(),
                $missing,
            )));
        }

        return $this->distributionErrorIgnoringCertifications($user, $item, $quantity);
    }

    /**
     * The same checks with the certification requirement set aside, for a desk that is deliberately
     * overriding one. Hours, quantity and availability still apply: an override answers for the
     * training the recipient has not done, not for hours they have not worked.
     */
    public function distributionErrorIgnoringCertifications(User $user, GoodieItem $item, int $quantity): ?string
    {
        if ($quantity < 1) {
            return 'Quantity must be at least 1.';
        }
        if (!$item->isActive() || !$item->getCategory()->isActive()) {
            return 'This item is not currently available.';
        }

        $hours = $this->hoursCache->get($user)->getTotalHours();
        if ($hours < $item->getRequiredHours()) {
            return \sprintf('Needs %.1f more hours (has %.1f of %.1f).', $item->getRequiredHours() - $hours, $hours, $item->getRequiredHours());
        }

        $max = $item->getMaxPerPerson();
        if ($max !== null) {
            $claimed = $this->distributions->quantityForUserAndItem($user, $item);
            if ($claimed + $quantity > $max) {
                return \sprintf('Exceeds the per-person limit (max %d, already %d).', $max, $claimed);
            }
        }

        return null;
    }
}
