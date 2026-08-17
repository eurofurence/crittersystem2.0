<?php

namespace App\Service;

use App\Entity\Certification;
use App\Entity\CertificationToken;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Notification\NotificationCategories;
use App\Repository\CertificationTokenRepository;
use App\Repository\UserCertificationRepository;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Certification workflow: apply / self-confirm / approve-by-QR, the manager's decisions on a
 * record, and short-lived QR token issuance for in-person verification.
 *
 * Every decision goes through the methods here rather than being written by a controller, so the
 * audit entry and the volunteer's notification cannot be left out by a new caller. A decision on a
 * certification changes what somebody is allowed to do; it is not a silent field update.
 */
final class CertificationService
{
    public const HOLDERS_PER_PAGE = 25;

    /** How far ahead a holder is warned that a certification is about to run out. */
    public const EXPIRY_WARNING_DAYS = 30;

    /**
     * States a volunteer may apply out of themselves.
     *
     * Revoked is not among them: somebody decided to take that certification away, and applying
     * again would put the request back in the queue as though it were routine. Those go back through
     * a manager, who can reinstate the record directly.
     */
    public const REAPPLICABLE = [
        UserCertification::STATUS_REJECTED,
        UserCertification::STATUS_EXPIRED,
    ];

    /** Holder sections, in the order the management page shows them: what needs attention first. */
    public const HOLDER_SECTIONS = [
        UserCertification::STATUS_PENDING,
        UserCertification::STATUS_APPROVED,
        UserCertification::STATUS_SELF_CONFIRMED,
        UserCertification::STATUS_EXPIRED,
        UserCertification::STATUS_REJECTED,
        UserCertification::STATUS_REVOKED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserCertificationRepository $userCerts,
        private readonly CertificationTokenRepository $tokens,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Grant the certification: an approval of a pending application, or the reinstatement of one
     * that was rejected, revoked or has expired.
     */
    public function approve(UserCertification $record, ?User $actor, ?string $reason = null): void
    {
        $record->setStatus(UserCertification::STATUS_APPROVED)
            ->setDateCertified(new \DateTimeImmutable())
            ->setDateExpires($this->calculateExpiry($record->getCertification()))
            ->setCertifiedBy($actor)
            ->setDecidedAt(new \DateTimeImmutable())
            ->setDecisionReason($reason)
            ->setExpiryRemindedAt(null);
        $this->em->flush();

        $this->record($record, $actor, AuditEvents::GRANT, $reason);
        $this->tell(
            $record,
            'Certification approved',
            \sprintf('Your "%s" certification was approved.', $record->getCertification()->getTitle()),
            $reason,
        );
    }

    /**
     * Grant a certification to somebody who never applied, or record an application on their behalf.
     *
     * Everything is the admin's to set: the status, the date it was earned (which may be in the past,
     * because paper certificates arrive with their own date on them) and the expiry (which may not
     * match the certification's validity period, because the paper says what it says).
     *
     * An existing record for this pair is reused rather than refused - the table holds one row per
     * user and certification, and somebody whose certification lapsed or was taken away is exactly
     * who an admin is most likely to be granting it to again. Reusing it clears the previous
     * decision reason, so a stale refusal does not sit on a record that now says the volunteer holds
     * this.
     */
    public function grant(
        Certification $certification,
        User $holder,
        string $status,
        ?\DateTimeImmutable $certified,
        ?\DateTimeImmutable $expires,
        ?string $notes,
        ?User $actor,
    ): UserCertification {
        $record = $this->userCerts->findOneByUserAndCertification($holder, $certification);
        if ($record === null) {
            $record = new UserCertification($holder, $certification);
            $this->em->persist($record);
        }

        $granting = $status !== UserCertification::STATUS_PENDING;
        $record->setStatus($status)
            ->setDateCertified($granting ? ($certified ?? new \DateTimeImmutable()) : null)
            ->setDateExpires($granting ? ($expires ?? $this->calculateExpiry($certification)) : null)
            ->setCertifiedBy($actor)
            ->setDecidedAt(new \DateTimeImmutable())
            ->setDecisionReason(null)
            ->setExpiryRemindedAt(null);
        if ($notes !== null && trim($notes) !== '') {
            $record->setNotes(trim($notes));
        }
        $this->em->flush();

        $this->record($record, $actor, AuditEvents::GRANT, $notes);

        if ($granting) {
            $this->tell(
                $record,
                'Certification added',
                \sprintf('"%s" was added to your certifications.', $certification->getTitle()),
                null,
            );
        }

        return $record;
    }

    /**
     * Turn down an application. The record keeps the reason and the date, because applying again
     * puts it back in the queue and whoever picks it up next has to see it was already refused once.
     */
    public function reject(UserCertification $record, ?User $actor, string $reason): void
    {
        $record->setStatus(UserCertification::STATUS_REJECTED)
            ->setDateCertified(null)
            ->setDateExpires(null)
            ->setCertifiedBy($actor)
            ->setDecidedAt(new \DateTimeImmutable())
            ->setDecisionReason($reason);
        $this->em->flush();

        $this->record($record, $actor, AuditEvents::STATUS_CHANGE, $reason);
        $this->tell(
            $record,
            'Certification application declined',
            \sprintf('Your application for "%s" was not approved.', $record->getCertification()->getTitle()),
            $reason,
        );
    }

    /** Take a granted certification away. */
    public function revoke(UserCertification $record, ?User $actor, string $reason): void
    {
        $record->setStatus(UserCertification::STATUS_REVOKED)
            ->setCertifiedBy($actor)
            ->setDecidedAt(new \DateTimeImmutable())
            ->setDecisionReason($reason);
        $this->em->flush();

        $this->record($record, $actor, AuditEvents::REVOKE, $reason);
        $this->tell(
            $record,
            'Certification revoked',
            \sprintf('Your "%s" certification was revoked.', $record->getCertification()->getTitle()),
            $reason,
        );
    }

    /**
     * Write the expiry that has already happened onto the records that still claim to be held, and
     * tell each holder.
     *
     * The pages compute this themselves, so nothing on screen depends on the job having run. What it
     * buys is the volunteer finding out: an expiry passes at an instant nobody is looking at, and
     * somebody who is not told simply turns up believing they are still qualified.
     *
     * @return int how many lapsed
     */
    public function markExpired(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        $expired = 0;
        foreach ($this->userCerts->findLapsed($now) as $record) {
            $record->setStatus(UserCertification::STATUS_EXPIRED);
            $this->em->flush();

            $this->audit->system(AuditEvents::CERTIFICATION, AuditEvents::EXPIRE, [
                'resourceType' => 'certification',
                'resourceId' => (string) $record->getCertification()->getUuid(),
                'resourceOwnerId' => $record->getUser()->getId(),
                'details' => ['certification' => $record->getCertification()->getTitle()],
            ]);
            $this->tell(
                $record,
                'Certification expired',
                \sprintf('Your "%s" certification has expired.', $record->getCertification()->getTitle()),
                null,
            );
            ++$expired;
        }

        return $expired;
    }

    /**
     * Warn holders whose certification runs out within the window, once each.
     *
     * @return int how many were warned
     */
    public function remindExpiring(int $days = self::EXPIRY_WARNING_DAYS, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $until = $now->modify(\sprintf('+%d days', $days));

        $warned = 0;
        foreach ($this->userCerts->findExpiringUnwarned($now, $until) as $record) {
            $record->setExpiryRemindedAt($now);
            $this->em->flush();

            $this->tell(
                $record,
                'Certification expiring',
                \sprintf(
                    'Your "%s" certification expires on %s. Renew it before then to keep taking shifts that need it.',
                    $record->getCertification()->getTitle(),
                    $record->getDateExpires()?->format('Y-m-d') ?? '',
                ),
                null,
            );
            ++$warned;
        }

        return $warned;
    }

    /**
     * Withdraw one's own application.
     *
     * The record is removed rather than kept in a withdrawn state: nobody decided anything, so there
     * is no decision to remember, and a volunteer who changes their mind twice should not leave a
     * trail of their own indecision on a manager's queue. The audit entry keeps the fact.
     */
    public function withdraw(UserCertification $record, ?User $actor): void
    {
        $certification = $record->getCertification();
        $holder = $record->getUser();

        $this->em->remove($record);
        $this->em->flush();

        $this->audit->log(AuditEvents::CERTIFICATION, AuditEvents::DELETE, [
            'resourceType' => 'certification',
            'resourceId' => (string) $certification->getUuid(),
            'resourceOwnerId' => $holder->getId(),
            'actorUser' => $actor,
            'details' => ['certification' => $certification->getTitle(), 'withdrawn' => true],
        ]);
    }

    /**
     * Take the certification away from everybody who currently holds it.
     *
     * The whole event's holders in one action: used when the certification itself turns out to have
     * been granted on a bad basis, not to clear up individual cases. Only records that count as held
     * are touched, so an already-revoked or declined one is left as it is rather than being restamped
     * with a reason that was never about that person.
     *
     * @return int how many were revoked
     */
    public function revokeAll(Certification $certification, ?User $actor, string $reason): int
    {
        $revoked = 0;
        foreach ($this->userCerts->findForCertification($certification) as $record) {
            if (\in_array($record->getStatus(), [UserCertification::STATUS_APPROVED, UserCertification::STATUS_SELF_CONFIRMED], true)) {
                $this->revoke($record, $actor, $reason);
                ++$revoked;
            }
        }

        return $revoked;
    }

    /**
     * The audit entry every decision leaves. The volunteer is the resource owner: an audit export
     * focused on one person has to surface what was decided about their certifications.
     */
    private function record(UserCertification $record, ?User $actor, string $action, ?string $reason): void
    {
        $this->audit->log(AuditEvents::CERTIFICATION, $action, [
            'resourceType' => 'certification',
            'resourceId' => (string) $record->getCertification()->getUuid(),
            'resourceOwnerId' => $record->getUser()->getId(),
            'actorUser' => $actor,
            'details' => [
                'certification' => $record->getCertification()->getTitle(),
                'status' => $record->getStatus(),
                'reason' => $reason,
            ],
        ]);
    }

    private function tell(UserCertification $record, string $title, string $message, ?string $reason): void
    {
        if ($reason !== null && trim($reason) !== '') {
            $message .= ' Reason: '.trim($reason);
        }

        $this->notifications->notify(
            $record->getUser(),
            NotificationCategories::CERTIFICATION,
            $title,
            $message,
            $this->urls->generate('app_certifications_index'),
        );
    }

    /**
     * Submit a new application. Returns null when the certification is inactive, or when the user
     * already holds a record for it whose status is not one of {@see REAPPLICABLE} (the caller
     * should flash a helpful message instead).
     *
     * A declined application may be made again, and an expired certification is renewed the same way.
     * The record returns to the queue but keeps the previous decision and its reason, so whoever
     * picks it up sees what happened last time rather than taking the request on trust.
     */
    public function applyFor(User $user, Certification $certification): ?UserCertification
    {
        if (!$certification->isActive()) {
            return null;
        }
        $record = $this->userCerts->findOneByUserAndCertification($user, $certification);
        if ($record !== null && !\in_array($record->getStatus(), self::REAPPLICABLE, true)) {
            return null;
        }

        if ($record === null) {
            $record = new UserCertification($user, $certification);
            $this->em->persist($record);
        }
        $record->setStatus(UserCertification::STATUS_PENDING);
        $this->em->flush();

        return $record;
    }

    /**
     * User-initiated confirmation, allowed only when the certification opts in.
     * Returns null if the certification doesn't allow self-confirm or the user
     * already holds a valid record.
     */
    public function selfConfirm(User $user, Certification $certification): ?UserCertification
    {
        if (!$certification->isActive() || !$certification->isAllowSelfConfirmation()) {
            return null;
        }

        $record = $this->userCerts->findOneByUserAndCertification($user, $certification);
        if ($record !== null && $record->isValid()) {
            return null;
        }

        $record ??= new UserCertification($user, $certification);
        $record->setStatus(UserCertification::STATUS_SELF_CONFIRMED)
            ->setDateCertified(new \DateTimeImmutable())
            ->setDateExpires($this->calculateExpiry($certification))
            ->setCertifiedBy(null)
            ->setExpiryRemindedAt(null);

        if ($record->getId() === null) {
            $this->em->persist($record);
        }
        $this->em->flush();

        return $record;
    }

    /**
     * Approve via an in-person QR scan: the scanning user must already have a
     * pending application. Returns ['record'=>UserCertification] on success or
     * ['error'=>string] otherwise (so the controller can render a clean message). The record is left
     * without a certifying admin, because a QR check-in identifies no individual.
     *
     * @return array{record?: UserCertification, error?: string}
     */
    public function approveByQr(User $user, Certification $certification): array
    {
        if (!$certification->isActive()) {
            return ['error' => 'This certification is not active.'];
        }

        $record = $this->userCerts->findOneByUserAndCertification($user, $certification);
        if ($record === null) {
            return ['error' => 'Apply for this certification first, then scan the QR.'];
        }
        if ($record->isValid()) {
            return ['error' => 'You are already certified for this.'];
        }

        $record->setStatus(UserCertification::STATUS_APPROVED)
            ->setDateCertified(new \DateTimeImmutable())
            ->setDateExpires($this->calculateExpiry($certification))
            ->setCertifiedBy(null)
            ->setExpiryRemindedAt(null);
        $this->em->flush();

        return ['record' => $record];
    }

    /**
     * Active QR token for a certification, creating one when missing or expired.
     *
     * A caller that is about to put the token on a screen passes the life it needs the token to
     * still have, and gets a fresh one when the active token is already inside that window.
     * Without it the QR display would render a token expiring seconds later, re-fetch itself at
     * that instant, and be handed the same nearly dead token again.
     */
    public function getOrCreateToken(Certification $certification, int $minimumRemainingSeconds = 0): CertificationToken
    {
        $token = $this->tokens->findActiveForCertification($certification);

        if (null === $token) {
            return $this->refreshToken($certification);
        }

        if ($minimumRemainingSeconds > 0
            && $token->getExpiresAt() <= new \DateTimeImmutable(sprintf('+%d seconds', $minimumRemainingSeconds))) {
            return $this->refreshToken($certification);
        }

        return $token;
    }

    public function refreshToken(Certification $certification): CertificationToken
    {
        $token = new CertificationToken($certification);
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    public function findActiveToken(string $token): ?CertificationToken
    {
        return $this->tokens->findActive($token);
    }

    /**
     * One certification's records grouped by what they count as today, in the order the holder page
     * shows them. Every status key is present even when nobody holds that state, so the page renders
     * the same sections whether or not anybody has applied yet.
     *
     * @return array<string, list<UserCertification>>
     */
    public function holdersByStatus(Certification $certification): array
    {
        $grouped = array_fill_keys(self::HOLDER_SECTIONS, []);
        foreach ($this->userCerts->findForCertification($certification) as $record) {
            $grouped[$record->effectiveStatus()][] = $record;
        }

        return $grouped;
    }

    /**
     * One page of holder rows, searched by the holder's username.
     *
     * Searching and slicing happen in PHP over the rows already loaded for the whole certification:
     * the page shows every section at once, so a query per section would read the same table five
     * times to answer one request.
     *
     * @param list<UserCertification> $records
     *
     * @return array{items: list<UserCertification>, total: int, totalAll: int, page: int, pages: int, query: string, perPage: int}
     */
    public function paginateHolders(array $records, string $query = '', int $page = 1, int $perPage = self::HOLDERS_PER_PAGE): array
    {
        $totalAll = \count($records);
        $query = trim($query);
        if ($query !== '') {
            $needle = mb_strtolower($query);
            $records = array_values(array_filter(
                $records,
                static fn (UserCertification $record): bool => str_contains(mb_strtolower($record->getUser()->getName()), $needle),
            ));
        }

        $total = \count($records);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        return [
            'items' => \array_slice($records, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'totalAll' => $totalAll,
            'page' => $page,
            'pages' => $pages,
            'query' => $query,
            'perPage' => $perPage,
        ];
    }

    private function calculateExpiry(Certification $certification): ?\DateTimeImmutable
    {
        if ($certification->isPerpetual() || ($days = $certification->getValidityPeriodDays()) === null || $days <= 0) {
            return null;
        }

        return (new \DateTimeImmutable())->modify("+{$days} days");
    }
}
