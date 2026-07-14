<?php

namespace App\Service;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\OperationalStatusOverride;
use App\Entity\User;
use App\Repository\OperationalStatusOverrideRepository;
use App\Repository\ShiftEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Computes and mutates a user's Operational Status.
 *
 * Effective status is derived, never stored: an active shift assignment always
 * yields "Not available"; otherwise a live "Free to help" override wins until it
 * expires; otherwise "No Shifts". Only "Free to help" is user-settable, for a
 * fixed duration.
 */
class OperationalStatusService
{
    public const NO_SHIFTS = 'no_shifts';
    public const FREE_TO_HELP = 'free_to_help';
    public const NOT_AVAILABLE = 'not_available';

    /** Selectable "Free to help" durations, in minutes. */
    public const DURATIONS = [30, 60, 120];

    private const LABELS = [
        self::NO_SHIFTS => 'No Shifts',
        self::FREE_TO_HELP => 'Free to help',
        self::NOT_AVAILABLE => 'Not available',
    ];

    public function __construct(
        private readonly ShiftEntryRepository $entries,
        private readonly OperationalStatusOverrideRepository $overrides,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function effectiveStatus(User $user, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();

        // An active shift assignment always wins.
        if ($this->entries->hasActiveShiftAt($user, $now)) {
            return self::NOT_AVAILABLE;
        }

        $override = $this->overrides->findOneByUser($user);
        if ($override !== null && $override->getValue() === self::FREE_TO_HELP && $override->isActive($now)) {
            return self::FREE_TO_HELP;
        }

        return self::NO_SHIFTS;
    }

    /**
     * @return array{value: string, label: string, freeToHelp: bool, expiresAt: ?\DateTimeImmutable, durations: int[]}
     */
    public function viewModel(User $user, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $status = $this->effectiveStatus($user, $now);
        $override = $this->overrides->findOneByUser($user);

        return [
            'value' => $status,
            'label' => self::LABELS[$status],
            'freeToHelp' => $status === self::FREE_TO_HELP,
            'expiresAt' => $status === self::FREE_TO_HELP && $override !== null ? $override->getExpiresAt() : null,
            'durations' => self::DURATIONS,
        ];
    }

    public function setFreeToHelp(User $user, int $minutes): void
    {
        if (!\in_array($minutes, self::DURATIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported free-to-help duration: %d.', $minutes));
        }

        $now = new \DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d minutes', $minutes));

        $override = $this->overrides->findOneByUser($user);
        if ($override === null) {
            $override = new OperationalStatusOverride($user, self::FREE_TO_HELP, $expiresAt);
            $this->em->persist($override);
        } else {
            $override->setValue(self::FREE_TO_HELP)->setExpiresAt($expiresAt);
        }
        $this->em->flush();

        $this->audit->log(AuditEvents::OPERATIONAL_STATUS, AuditEvents::STATUS_CHANGE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => [
                'status' => self::FREE_TO_HELP,
                'minutes' => $minutes,
                'until' => $expiresAt->format(\DATE_ATOM),
            ],
        ]);
    }

    public function clear(User $user): void
    {
        $override = $this->overrides->findOneByUser($user);
        if ($override === null) {
            return;
        }

        $this->em->remove($override);
        $this->em->flush();

        $this->audit->log(AuditEvents::OPERATIONAL_STATUS, AuditEvents::STATUS_CHANGE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['status' => 'cleared'],
        ]);
    }
}
