<?php

namespace App\Service;

use App\Entity\GoodieItem;
use App\Entity\User;
use App\Repository\GoodieDistributionRepository;
use App\Repository\GoodieItemRepository;

/**
 * Decides which goodie items a user can claim based on their cached hours and
 * prior distributions. Certification requirements are a documented
 * hook, enforced once the certification workflow exists.
 */
final class GoodieEligibilityService
{
    public function __construct(
        private readonly HoursCacheService $hoursCache,
        private readonly GoodieItemRepository $items,
        private readonly GoodieDistributionRepository $distributions,
    ) {
    }

    /**
     * Evaluate all active items for a user into the three tiers:
     * eligible (claim now), pending (hours gap), claimed (at per-person max).
     *
     * @return array{hours: float, rows: list<array{item: GoodieItem, tier: string, claimed: int, gap: float, remaining: ?int}>}
     */
    public function evaluate(User $user): array
    {
        $hours = $this->hoursCache->get($user)->getTotalHours();

        $rows = [];
        foreach ($this->items->findActiveForDistribution() as $item) {
            $claimed = $this->distributions->quantityForUserAndItem($user, $item);
            $atMax = $item->getMaxPerPerson() !== null && $claimed >= $item->getMaxPerPerson();
            $meetsHours = $hours >= $item->getRequiredHours();

            $tier = $atMax ? 'claimed' : ($meetsHours ? 'eligible' : 'pending');

            $rows[] = [
                'item' => $item,
                'tier' => $tier,
                'claimed' => $claimed,
                'gap' => max(0.0, $item->getRequiredHours() - $hours),
                'remaining' => $item->getMaxPerPerson() !== null ? $item->getMaxPerPerson() - $claimed : null,
            ];
        }

        return ['hours' => $hours, 'rows' => $rows];
    }

    /**
     * Reason the item cannot be given to the user in the requested quantity, or
     * null when the distribution is allowed.
     */
    public function distributionError(User $user, GoodieItem $item, int $quantity): ?string
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
