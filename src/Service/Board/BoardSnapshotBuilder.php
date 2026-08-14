<?php

namespace App\Service\Board;

use App\Entity\Department;
use App\Entity\DutyRecord;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Repository\DutyRecordRepository;
use App\Repository\HelpCallRepository;
use App\Repository\NeededVolunteerTypeRepository;
use App\Repository\ShiftEntryRepository;
use App\Repository\ShiftRepository;
use App\Repository\WorklogRepository;
use App\Service\Board\Attention\AttentionItem;
use App\Service\Board\Attention\AttentionRule;
use App\Service\DisplaySettings;
use App\Service\HoursCalculator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Builds the whole board for one department and one day.
 *
 * Everything the board shows comes from here, and from one pass over the data: the initial render,
 * every live refresh and every attention rule read the same {@see BoardContext}, so two boards
 * looking at the same department cannot disagree with each other or with themselves.
 *
 * The board is open all day on several machines, so every relation it needs is fetched in a batched
 * query up front rather than walked per row.
 */
final class BoardSnapshotBuilder
{
    /** @param iterable<AttentionRule> $rules */
    public function __construct(
        private readonly ShiftRepository $shifts,
        private readonly ShiftEntryRepository $entries,
        private readonly NeededVolunteerTypeRepository $needs,
        private readonly DutyRecordRepository $duties,
        private readonly WorklogRepository $worklogs,
        private readonly HelpCallRepository $helpCalls,
        private readonly HoursCalculator $hours,
        private readonly BoardSettings $settings,
        private readonly DisplaySettings $display,
        #[AutowireIterator(AttentionRule::class)]
        private readonly iterable $rules,
    ) {
    }

    public function build(Department $department, \DateTimeImmutable $day, ?\DateTimeImmutable $now = null): BoardSnapshot
    {
        $context = $this->context($department, $day, $now ?? new \DateTimeImmutable());

        $calls = $this->helpCalls->findActiveForShifts($context->shifts);

        $rows = [];
        foreach ($context->shifts as $shift) {
            $rows[] = new BoardShiftRow(
                $shift,
                $context->neededFor($shift),
                $context->assignedFor($shift),
                $context->presentOn($shift),
                ShiftStatus::of($context, $shift),
                $context->isRunning($shift),
                $calls[$shift->getId()] ?? null,
            );
        }

        $volunteers = $this->volunteers($context);
        $attention = AttentionItem::rank(AttentionItem::deduplicate($this->attention($context)));
        $forecast = StaffingForecast::build($context);

        return new BoardSnapshot(
            $department,
            $day,
            $context->now,
            \count($context->activeUsers()),
            \count($context->users),
            $this->openPositions($context, $rows),
            \count(array_filter($rows, static fn (BoardShiftRow $row): bool => $row->isRunning)),
            $this->nextShift($context, $rows),
            $attention,
            BoardVolunteer::rankByLoad(array_values(array_filter($volunteers, static fn (BoardVolunteer $v): bool => $v->isPresent()))),
            BoardVolunteer::rankByLoad($volunteers),
            $rows,
            BoardShiftRow::rankForStaffing(array_values(array_filter(
                $rows,
                static fn (BoardShiftRow $row): bool => $row->status->needsStaffing(),
            ))),
            $this->comingNext($context),
            $this->recentlyOff($context),
            WorkloadDistribution::build($context),
            $forecast,
            $this->nextTransitionAt($context, $rows),
        );
    }

    private function context(Department $department, \DateTimeImmutable $day, \DateTimeImmutable $now): BoardContext
    {
        $tz = $this->display->timezone();
        $dayStart = $day->setTimezone($tz)->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $shifts = $this->shifts->findForDepartmentOverlapping($department, $dayStart, $dayEnd);
        $entriesByShift = $this->entries->findForShifts($shifts);
        $duties = $this->duties->findForDepartmentOverlapping($department, $dayStart, $dayEnd);

        /** @var array<int, User> $users */
        $users = [];
        $roles = [];
        foreach ($entriesByShift as $entries) {
            foreach ($entries as $entry) {
                $users[$entry->getUser()->getId()] = $entry->getUser();
                $roles[$entry->getUser()->getId()] ??= $entry->getVolunteerType()->getName();
            }
        }
        foreach ($duties as $duty) {
            $users[$duty->getUser()->getId()] = $duty->getUser();
        }

        $entriesByUser = $this->entries->findByUsers(array_values($users));
        $worklogHours = $this->worklogs->sumHoursForUsers(array_values($users));

        $totalHours = [];
        $todayHours = [];
        foreach ($users as $id => $user) {
            $own = $entriesByUser[$id] ?? [];
            $totalHours[$id] = $this->hours->breakdown($own)->total() + ($worklogHours[$id] ?? 0.0);
            $todayHours[$id] = $this->hours->breakdown(array_filter(
                $own,
                static fn (ShiftEntry $entry): bool => $entry->getShift()->getStartsAt() < $dayEnd
                    && $entry->getShift()->getEndsAt() > $dayStart,
            ))->total();
        }

        return new BoardContext(
            $department,
            $dayStart,
            $dayStart,
            $dayEnd,
            $now,
            $shifts,
            $this->needs->findEffectiveForShifts($shifts),
            $entriesByShift,
            $entriesByUser,
            $this->presence($entriesByShift, $duties),
            $users,
            $totalHours,
            $todayHours,
            $roles,
            $this->settings,
        );
    }

    /**
     * Merge both records of somebody being present into one timeline each.
     *
     * A volunteer can be checked in against a shift, on an open duty session, or both at once, and
     * the two overlap constantly. Merging first is what makes "how long have they been here without
     * a break" answerable at all - counted separately, a handover between two shifts would look like
     * a break that never happened.
     *
     * @param array<int, list<ShiftEntry>> $entriesByShift
     * @param DutyRecord[]                 $duties
     *
     * @return array<int, list<PresenceSpan>>
     */
    private function presence(array $entriesByShift, array $duties): array
    {
        $spans = [];

        foreach ($entriesByShift as $entries) {
            foreach ($entries as $entry) {
                $checkedIn = $entry->getCheckedInAt();
                if ($checkedIn === null) {
                    continue;
                }
                $spans[$entry->getUser()->getId()][] = new PresenceSpan($checkedIn, $entry->getCheckedOutAt());
            }
        }

        foreach ($duties as $duty) {
            $spans[$duty->getUser()->getId()][] = new PresenceSpan($duty->getStartedAt(), $duty->getEndedAt());
        }

        foreach ($spans as $id => $own) {
            $spans[$id] = PresenceSpan::merge($own);
        }

        return $spans;
    }

    /** @return list<BoardVolunteer> */
    private function volunteers(BoardContext $context): array
    {
        $cap = $context->settings->hoursCap();
        $volunteers = [];

        foreach ($context->users as $id => $user) {
            $open = $context->openSpanFor($user);
            $next = $this->nextShiftFor($context, $id);

            $volunteers[] = new BoardVolunteer(
                $user,
                $context->roleFor($user),
                $context->todayHoursFor($user),
                $context->totalHoursFor($user),
                $cap,
                $open?->startedAt,
                $this->presentMinutesToday($context, $user),
                \count(array_filter(
                    $context->entriesByUser[$id] ?? [],
                    static fn (ShiftEntry $entry): bool => $entry->getShift()->getStartsAt() < $context->dayEnd
                        && $entry->getShift()->getEndsAt() > $context->dayStart,
                )),
                $next,
                match (true) {
                    $open !== null => BoardVolunteer::STATUS_ON_DUTY,
                    $next !== null && $next > $context->now => BoardVolunteer::STATUS_ARRIVING,
                    default => BoardVolunteer::STATUS_OFF,
                },
            );
        }

        return $volunteers;
    }

    private function presentMinutesToday(BoardContext $context, User $user): int
    {
        $minutes = 0;
        foreach ($context->spansFor($user) as $span) {
            if (!$span->overlaps($context->dayStart, $context->dayEnd)) {
                continue;
            }
            $from = max($span->startedAt, $context->dayStart);
            $to = min($span->endedAt ?? $context->now, $context->dayEnd);
            if ($to > $from) {
                $minutes += intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
            }
        }

        return $minutes;
    }

    private function nextShiftFor(BoardContext $context, ?int $userId): ?\DateTimeImmutable
    {
        $next = null;
        foreach ($context->entriesByUser[$userId] ?? [] as $entry) {
            $startsAt = $entry->getShift()->getStartsAt();
            if ($startsAt <= $context->now) {
                continue;
            }
            if ($next === null || $startsAt < $next) {
                $next = $startsAt;
            }
        }

        return $next;
    }

    /**
     * Slots that need somebody now: running shifts and ones inside the imminent window. A gap four
     * hours out is a planning matter and belongs in the forecast, not in a tile counting problems.
     *
     * @param list<BoardShiftRow> $rows
     */
    private function openPositions(BoardContext $context, array $rows): int
    {
        $window = $context->settings->preStartWarnMinutes();
        $open = 0;

        foreach ($rows as $row) {
            $imminent = $row->shift->getStartsAt()->modify(\sprintf('-%d minutes', $window)) <= $context->now;
            if ($row->shift->getEndsAt() > $context->now && $imminent) {
                $open += $row->shortfall();
            }
        }

        return $open;
    }

    /** @param list<BoardShiftRow> $rows */
    private function nextShift(BoardContext $context, array $rows): ?BoardShiftRow
    {
        $next = null;
        foreach ($rows as $row) {
            if ($row->shift->getStartsAt() <= $context->now) {
                continue;
            }
            if ($next === null || $row->shift->getStartsAt() < $next->shift->getStartsAt()) {
                $next = $row;
            }
        }

        return $next;
    }

    /** @return list<BoardArrival> */
    private function comingNext(BoardContext $context): array
    {
        $until = $context->now->modify(\sprintf('+%d minutes', $context->settings->comingWindowMinutes()));
        $arrivals = [];

        foreach ($context->shifts as $shift) {
            if ($shift->getStartsAt() <= $context->now || $shift->getStartsAt() > $until) {
                continue;
            }
            foreach ($context->entriesFor($shift) as $entry) {
                $arrivals[] = new BoardArrival(
                    $entry->getUser(),
                    $entry->getVolunteerType()->getName(),
                    $shift->getStartsAt(),
                    $shift,
                );
            }
        }

        return BoardArrival::soonestFirst($arrivals);
    }

    /** @return list<BoardArrival> */
    private function recentlyOff(BoardContext $context): array
    {
        $since = $context->now->modify(\sprintf('-%d minutes', $context->settings->offDutyWindowMinutes()));
        $departures = [];

        foreach ($context->users as $user) {
            foreach ($context->spansFor($user) as $span) {
                if ($span->endedAt === null || $span->endedAt < $since || $span->endedAt > $context->now) {
                    continue;
                }
                $departures[] = new BoardArrival($user, $context->roleFor($user), $span->endedAt, null);
            }
        }

        return BoardArrival::mostRecentFirst($departures);
    }

    /** @return list<AttentionItem> */
    private function attention(BoardContext $context): array
    {
        $items = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($context) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The next instant at which this board's content changes without anybody doing anything.
     *
     * The board has no heartbeat: instead of re-rendering every minute in case something moved, it
     * declares the exact moment it will next differ and re-fetches then, and the fresh render carries
     * the moment after that. A shift starting, a warning window opening, somebody crossing the
     * continuous-presence limit and the forecast rolling into the next hour are all computable
     * instants, so nothing here needs guessing.
     *
     * The forecast boundary always exists, which means the chain can never stall.
     *
     * @param list<BoardShiftRow> $rows
     */
    private function nextTransitionAt(BoardContext $context, array $rows): ?\DateTimeImmutable
    {
        $moments = [];

        foreach ($rows as $row) {
            $moments[] = $row->shift->getStartsAt();
            $moments[] = $row->shift->getEndsAt();
        }

        foreach ($this->rules as $rule) {
            foreach ($rule->transitions($context) as $moment) {
                $moments[] = $moment;
            }
        }

        $moments[] = $context->now->setTime((int) $context->now->format('H'), 0)
            ->modify(\sprintf('+%d hours', $context->settings->forecastStepHours()));

        $next = null;
        foreach ($moments as $moment) {
            if ($moment <= $context->now) {
                continue;
            }
            if ($next === null || $moment < $next) {
                $next = $moment;
            }
        }

        return $next;
    }
}
