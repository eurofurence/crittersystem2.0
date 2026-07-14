<?php

declare(strict_types=1);

namespace App\TwoFactor;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enrolment, verification and recovery-code management for TOTP two-factor auth.
 * Recovery codes are single-use; verifying one consumes it.
 */
final class TwoFactorService
{
    private const BACKUP_CODE_COUNT = 8;

    public function __construct(
        private readonly TotpService $totp,
        private readonly EntityManagerInterface $em,
        private readonly string $issuer = 'Critter',
    ) {
    }

    public function newSecret(): string
    {
        return $this->totp->generateSecret();
    }

    public function provisioningUri(User $user, string $secret): string
    {
        return $this->totp->provisioningUri($user->getUserIdentifier(), $this->issuer, $secret);
    }

    /**
     * Enable 2FA with a confirmed secret and return the freshly generated
     * recovery codes (shown to the user once).
     *
     * @return string[]
     */
    public function enable(User $user, string $secret): array
    {
        $codes = $this->generateBackupCodes();
        $user->setTotpSecret($secret)->setTwoFactorEnabled(true)->setBackupCodes($codes);
        $this->em->flush();

        return $codes;
    }

    /**
     * Confirm an enrolment: if the code matches the pending secret, enable 2FA
     * and return the recovery codes; otherwise null.
     *
     * @return string[]|null
     */
    public function tryEnable(User $user, string $secret, string $code): ?array
    {
        if (!$this->totp->verify($secret, $code)) {
            return null;
        }

        return $this->enable($user, $secret);
    }

    public function disable(User $user): void
    {
        $user->setTotpSecret(null)->setTwoFactorEnabled(false)->setBackupCodes([]);
        $this->em->flush();
    }

    /**
     * Generate a fresh set of recovery codes for an already-enrolled user,
     * discarding any previous ones. Returns the new codes (shown once).
     *
     * @return string[]
     */
    public function regenerateBackupCodes(User $user): array
    {
        $codes = $this->generateBackupCodes();
        $user->setBackupCodes($codes);
        $this->em->flush();

        return $codes;
    }

    /** How many single-use recovery codes the user has left. */
    public function remainingBackupCodeCount(User $user): int
    {
        return \count($user->getBackupCodes());
    }

    /** Verify a TOTP code or consume a recovery code. */
    public function verify(User $user, string $code): bool
    {
        if (!$user->isTwoFactorEnabled() || $user->getTotpSecret() === null) {
            return false;
        }

        if ($this->totp->verify($user->getTotpSecret(), $code)) {
            return true;
        }

        return $this->consumeBackupCode($user, $code);
    }

    private function consumeBackupCode(User $user, string $code): bool
    {
        $normalized = $this->normalize($code);
        $remaining = [];
        $matched = false;
        foreach ($user->getBackupCodes() as $stored) {
            if (!$matched && hash_equals($this->normalize($stored), $normalized)) {
                $matched = true;
                continue;
            }
            $remaining[] = $stored;
        }

        if ($matched) {
            $user->setBackupCodes($remaining);
            $this->em->flush();
        }

        return $matched;
    }

    /** @return string[] */
    private function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; ++$i) {
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $codes[] = substr($raw, 0, 4).'-'.substr($raw, 4, 4);
        }

        return $codes;
    }

    private function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '');
    }
}
