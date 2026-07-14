<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserHoursCache;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserHoursCacheRepository;
use App\Repository\WorklogRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds and caches the per-user hours breakdown used by goodies eligibility.
 * Only **completed** (already-ended) shifts count toward goodie
 * hours, unlike {@see HoursCalculator::totalForUser()} which projects all sign-ups.
 */
final class HoursCacheService
{
    private const CACHE_TTL_HOURS = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftEntryRepository $entries,
        private readonly WorklogRepository $worklogs,
        private readonly UserHoursCacheRepository $caches,
        private readonly HoursCalculator $calculator,
    ) {
    }

    /** Return the cached breakdown, recalculating when missing or stale (>24h). */
    public function get(User $user, bool $forceRefresh = false): UserHoursCache
    {
        $cache = $this->caches->findOneByUser($user);

        if (!$forceRefresh && $cache !== null && $this->isFresh($cache)) {
            return $cache;
        }

        return $this->recalculate($user, $cache);
    }

    public function recalculate(User $user, ?UserHoursCache $cache = null): UserHoursCache
    {
        $cache ??= $this->caches->findOneByUser($user) ?? new UserHoursCache($user);

        // Goodie hours count completed (already-ended) shifts only. Overlapping
        // time is deduplicated by the shared breakdown.
        $completedEntries = array_filter(
            $this->entries->findByUserOrdered($user),
            static fn ($entry) => $entry->getShift()->isPast(),
        );
        $breakdown = $this->calculator->breakdown($completedEntries);

        $worklogHours = $this->worklogs->sumHoursForUser($user);

        $cache->setDayShiftsHours($breakdown->dayHours)
            ->setNightShiftsHours($breakdown->nightHours)
            ->setNoshowPenaltyHours($breakdown->noshowPenaltyHours)
            ->setWorklogHours($worklogHours)
            ->setTotalHours($breakdown->total() + $worklogHours)
            ->setCompletedShiftsCount($breakdown->completedCount)
            ->setNightShiftsCount($breakdown->nightCount)
            ->setNoshowShiftsCount($breakdown->noshowCount)
            ->setLastCalculatedAt(new \DateTimeImmutable());

        if ($cache->getId() === null) {
            $this->em->persist($cache);
        }
        $this->em->flush();

        return $cache;
    }

    private function isFresh(UserHoursCache $cache): bool
    {
        $age = (new \DateTimeImmutable())->getTimestamp() - $cache->getLastCalculatedAt()->getTimestamp();

        return $age < self::CACHE_TTL_HOURS * 3600;
    }
}
