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
}
