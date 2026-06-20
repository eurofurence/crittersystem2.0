<?php

namespace App\Service;

use App\Entity\Certification;
use App\Entity\CertificationToken;
use App\Entity\User;
use App\Entity\UserCertification;
use App\Repository\CertificationTokenRepository;
use App\Repository\UserCertificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Certification workflow: apply / self-confirm / approve-by-QR plus
 * short-lived QR token issuance for in-person verification
 */
final class CertificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserCertificationRepository $userCerts,
        private readonly CertificationTokenRepository $tokens,
    ) {
    }

    /**
     * Submit a new application. Returns null when the user already has a
     * non-revoked/expired record for this certification (caller should flash a
     * helpful message instead)
     */
    public function applyFor(User $user, Certification $certification): ?UserCertification
    {
        if (!$certification->isActive()) {
            return null;
        }
        if ($this->userCerts->findOneByUserAndCertification($user, $certification) !== null) {
            return null;
        }

        $record = new UserCertification($user, $certification);
        $record->setStatus(UserCertification::STATUS_PENDING);
        $this->em->persist($record);
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
            ->setCertifiedBy(null);

        if ($record->getId() === null) {
            $this->em->persist($record);
        }
        $this->em->flush();

        return $record;
    }

    /**
     * Approve via an in-person QR scan: the scanning user must already have a
     * pending application. Returns ['record'=>UserCertification] on success or
     * ['error'=>string] otherwise (so the controller can render a clean message).
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
            ->setCertifiedBy(null); // QR check-in has no specific admin
        $this->em->flush();

        return ['record' => $record];
    }

    /** Active QR token for a certification, creating one when missing/expired. */
    public function getOrCreateToken(Certification $certification): CertificationToken
    {
        return $this->tokens->findActiveForCertification($certification) ?? $this->refreshToken($certification);
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

    private function calculateExpiry(Certification $certification): ?\DateTimeImmutable
    {
        if ($certification->isPerpetual() || ($days = $certification->getValidityPeriodDays()) === null || $days <= 0) {
            return null;
        }

        return (new \DateTimeImmutable())->modify("+{$days} days");
    }
}
