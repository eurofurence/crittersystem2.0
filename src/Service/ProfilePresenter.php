<?php

namespace App\Service;

use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Repository\BannedIdentityRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Repository\WorklogRepository;

/**
 * Assembles the view model for the unified user profile: header,
 * chronological work history (shifts + worklogs), Volunteer Type memberships and
 * the goodies tracker.
 */
class ProfilePresenter
{
    public function __construct(
        private readonly ShiftEntryRepository $entries,
        private readonly OperationalStatusService $status,
        private readonly WorklogRepository $worklogs,
        private readonly HoursCalculator $hours,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly GoodieEligibilityService $goodies,
        private readonly BannedIdentityRepository $bans,
        private readonly NoShowBanService $noShowBans,
    ) {
    }

    /**
     * Ban history and current no-show standing for the admin profile review.
     *
     * @return array{count: int, threshold: int, bans: array<int, array<string, mixed>>}
     */
    public function banReview(User $user): array
    {
        $bans = [];
        foreach ($this->bans->findByUser($user) as $ban) {
            $bans[] = [
                'bannedAt' => $ban->getBannedAt(),
                'reason' => $ban->getReason(),
                'automatic' => $ban->isAutomatic(),
                'appeal' => $ban->hasAppeal(),
                'noShowCount' => $ban->getNoShowCount(),
            ];
        }

        return [
            'count' => $this->noShowBans->noShowCount($user),
            'threshold' => $this->noShowBans->threshold(),
            'bans' => $bans,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function header(User $user): array
    {
        $now = new \DateTimeImmutable();
        $personalData = $user->getPersonalData();
        $state = $user->getState();
        $next = $this->entries->findNextUpcoming($user, $now);

        return [
            'user' => $user,
            'pronoun' => $personalData?->getPronoun(),
            'hasAvatar' => $personalData?->getAvatarPath() !== null,
            'priorityBadge' => $user->getPositionBadge(),
            'badges' => $user->getBadges(),
            'status' => $this->status->viewModel($user, $now)['label'],
            'plannedArrival' => $personalData?->getPlannedArrivalDate(),
            'arrived' => $state?->isArrived() ?? false,
            'arrivalDate' => $state?->getArrivalDate(),
            'nextShiftAt' => $next?->getShift()->getStartsAt(),
            'totalShifts' => $this->entries->countForUser($user),
        ];
    }

    /**
     * Merged, chronological work history: shift assignments and manual worklog
     * entries. Worklog rows are flagged so the UI can distinguish them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function workHistory(User $user): array
    {
        $now = new \DateTimeImmutable();
        $rows = [];

        foreach ($this->entries->findByUserOrdered($user) as $entry) {
            $shift = $entry->getShift();
            $rows[] = [
                'kind' => 'shift',
                'sort' => $shift->getStartsAt(),
                'start' => $shift->getStartsAt(),
                'end' => $shift->getEndsAt(),
                'duration' => $shift->getDurationHours(),
                'location' => $shift->getLocation()?->getName(),
                'volunteerType' => $entry->getVolunteerType(),
                'status' => $this->shiftStatus($entry, $now),
                'rewarded' => $this->hours->entryHours($entry),
            ];
        }

        foreach ($this->worklogs->findByUserOrdered($user) as $worklog) {
            $rows[] = [
                'kind' => 'worklog',
                'sort' => $worklog->getWorkedAt(),
                'start' => $worklog->getWorkedAt(),
                'comment' => $worklog->getComment(),
                'creator' => $worklog->getCreator()?->getName(),
                'rewarded' => $worklog->getHours(),
                'worklogId' => $worklog->getId(),
                'worklogUuid' => $worklog->getUuid(),
                // Self-editable only when the user recorded it for themselves.
                'editable' => $worklog->getCreator() !== null
                    && $worklog->getCreator()->getId() === $worklog->getUser()->getId(),
            ];
        }

        usort($rows, static fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return $rows;
    }

    /**
     * @return array<int, array{type: \App\Entity\VolunteerType, confirmed: bool}>
     */
    public function memberships(User $user): array
    {
        $rows = [];
        foreach ($this->memberships->findByUser($user) as $membership) {
            $rows[] = [
                'type' => $membership->getVolunteerType(),
                'confirmed' => $membership->isConfirmed(),
            ];
        }

        return $rows;
    }

    /**
     * Goodies grouped by tier for the tracker.
     *
     * @return array{hours: float, eligible: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>, claimed: array<int, array<string, mixed>>}
     */
    public function goodies(User $user): array
    {
        $evaluation = $this->goodies->evaluate($user);
        $grouped = ['hours' => $evaluation['hours'], 'eligible' => [], 'pending' => [], 'claimed' => []];
        foreach ($evaluation['rows'] as $row) {
            $grouped[$row['tier']][] = $row;
        }

        return $grouped;
    }

    private function shiftStatus(ShiftEntry $entry, \DateTimeImmutable $now): string
    {
        if ($entry->isNoshow()) {
            return 'no-show';
        }

        $shift = $entry->getShift();
        if ($now < $shift->getStartsAt()) {
            return 'next';
        }

        return $now <= $shift->getEndsAt() ? 'running' : 'done';
    }
}
