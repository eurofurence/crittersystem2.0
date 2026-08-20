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
    /**
     * How long a row may go unverified before a read redoes it.
     *
     * This is the backstop, not the mechanism: the sweep keeps rows current, and a shorter window
     * here only decides how far hours can drift if that sweep stops running. It was a day, which is
     * why hours appeared frozen between manual refreshes.
     */
    private const CACHE_TTL_SECONDS = 3600;

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

    /**
     * Same contract as {@see get()} for a whole set of users, but the fresh ones are fetched in a
     * single query rather than one per user. Stale entries still recalculate individually, so a
     * list screen pays that cost only for the rows it actually shows.
     *
     * @param User[] $users
     *
     * @return array<int, UserHoursCache> keyed by user id
     */
    public function getMany(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $existing = $this->caches->findByUsers($users);

        $result = [];
        foreach ($users as $user) {
            $cache = $existing[$user->getId()] ?? null;
            $result[$user->getId()] = ($cache !== null && $this->isFresh($cache))
                ? $cache
                : $this->recalculate($user, $cache);
        }

        return $result;
    }

    /**
     * Goodie hours count completed (already-ended) shifts only, and overlapping time is counted
     * once: the shared breakdown deduplicates it.
     */
    public function recalculate(User $user, ?UserHoursCache $cache = null): UserHoursCache
    {
        $cache ??= $this->caches->findOneByUser($user) ?? new UserHoursCache($user);

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
            ->setLastCalculatedAt(new \DateTimeImmutable())
            ->setDirty(false);

        if ($cache->getId() === null) {
            $this->em->persist($cache);
        }
        $this->em->flush();

        return $cache;
    }

    /** A row flagged by a write is never fresh, however recently it was calculated. */
    private function isFresh(UserHoursCache $cache): bool
    {
        $age = (new \DateTimeImmutable())->getTimestamp() - $cache->getLastCalculatedAt()->getTimestamp();

        return !$cache->isDirty() && $age < self::CACHE_TTL_SECONDS;
    }
}
