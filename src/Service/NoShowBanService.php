<?php

namespace App\Service;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Gdpr\BanChecker;
use App\Repository\ShiftEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Automatic no-show banning. Counts a user's no-show shifts since
 * their baseline and, when the configured threshold is reached, locks the
 * account through the existing (extended) ban mechanism. Unbanning resets the
 * baseline so historical no-shows still count toward rewarded hours but not
 * toward the next automatic ban.
 */
class NoShowBanService
{
    public function __construct(
        private readonly ShiftEntryRepository $entries,
        private readonly BanChecker $bans,
        private readonly EventConfigStore $config,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function threshold(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BAN_NOSHOW_THRESHOLD,
            EventConfigStore::DEFAULT_BAN_NOSHOW_THRESHOLD,
        ));
    }

    public function noShowCount(User $user): int
    {
        return $this->entries->countNoShowsSince($user, $user->getNoShowBaselineAt());
    }

    /**
     * Ban the user when they have reached the no-show threshold and are not yet
     * banned. Returns true when a ban was created.
     */
    public function evaluate(User $user): bool
    {
        if ($this->bans->isUserBanned($user)) {
            return false;
        }

        $threshold = $this->threshold();
        $count = $this->noShowCount($user);
        if ($count < $threshold) {
            return false;
        }

        $reason = sprintf('Automatic ban: %d no-show shifts reached the threshold of %d.', $count, $threshold);
        $this->bans->banUser($user, $reason, true, $count);
        $this->em->flush();

        $this->audit->log(AuditEvents::SECURITY, AuditEvents::BAN, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['automatic' => true, 'no_show_count' => $count, 'threshold' => $threshold],
        ]);

        return true;
    }

    /**
     * Lift all of the user's bans and reset the no-show baseline so the counter
     * starts fresh. Historical no-shows are preserved.
     */
    public function liftAndReset(User $user, ?string $reason = null): void
    {
        $removed = $this->bans->liftUser($user);
        $user->setNoShowBaselineAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->audit->log(AuditEvents::SECURITY, AuditEvents::UNBAN, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['removed' => $removed, 'reason' => $reason, 'counter_reset' => true],
        ]);
    }
}
