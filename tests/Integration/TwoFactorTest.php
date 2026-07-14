<?php

namespace App\Tests\Integration;

use App\Entity\User;
use App\Tests\DatabaseTestCase;
use App\TwoFactor\TotpService;
use App\TwoFactor\TwoFactorService;

final class TwoFactorTest extends DatabaseTestCase
{
    private function makeUser(): User
    {
        $user = new User();
        $user->setName('totp')->setEmail('totp@example.com')->setApiKey(bin2hex(random_bytes(16)))->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testEnableProducesWorkingTotpAndRecoveryCodes(): void
    {
        /** @var TwoFactorService $svc */
        $svc = static::getContainer()->get(TwoFactorService::class);
        /** @var TotpService $totp */
        $totp = static::getContainer()->get(TotpService::class);

        $user = $this->makeUser();
        $secret = $svc->newSecret();
        $codes = $svc->enable($user, $secret);

        self::assertTrue($user->isTwoFactorEnabled());
        self::assertCount(8, $codes);

        $code = $totp->codeForCounter($secret, intdiv(time(), 30));
        self::assertTrue($svc->verify($user, $code), 'valid TOTP accepted');
        self::assertFalse($svc->verify($user, '000000'), 'wrong code rejected');
    }

    public function testRecoveryCodeIsSingleUse(): void
    {
        /** @var TwoFactorService $svc */
        $svc = static::getContainer()->get(TwoFactorService::class);
        $user = $this->makeUser();
        $codes = $svc->enable($user, $svc->newSecret());
        $code = $codes[0];

        self::assertTrue($svc->verify($user, $code), 'recovery code works once');
        self::assertFalse($svc->verify($user, $code), 'and cannot be reused');
    }

    public function testRegenerateReplacesCodesAndInvalidatesOldOnes(): void
    {
        /** @var TwoFactorService $svc */
        $svc = static::getContainer()->get(TwoFactorService::class);
        $user = $this->makeUser();
        $original = $svc->enable($user, $svc->newSecret());

        $fresh = $svc->regenerateBackupCodes($user);
        self::assertCount(8, $fresh);
        self::assertSame(8, $svc->remainingBackupCodeCount($user));
        self::assertEmpty(array_intersect($original, $fresh), 'a fresh set is issued');
        self::assertFalse($svc->verify($user, $original[0]), 'old codes no longer work');
        self::assertTrue($svc->verify($user, $fresh[0]), 'new codes work');
    }

    public function testRemainingCountTracksConsumption(): void
    {
        /** @var TwoFactorService $svc */
        $svc = static::getContainer()->get(TwoFactorService::class);
        $user = $this->makeUser();
        $codes = $svc->enable($user, $svc->newSecret());

        self::assertSame(8, $svc->remainingBackupCodeCount($user));
        $svc->verify($user, $codes[0]);
        self::assertSame(7, $svc->remainingBackupCodeCount($user));
    }
}
