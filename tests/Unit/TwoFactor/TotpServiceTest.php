<?php

namespace App\Tests\Unit\TwoFactor;

use App\TwoFactor\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    public function testGeneratedSecretIsBase32(): void
    {
        $secret = (new TotpService())->generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testCodeVerifiesWithinWindow(): void
    {
        $totp = new TotpService();
        $secret = $totp->generateSecret();
        $ts = 1_700_000_000;
        $code = $totp->codeForCounter($secret, intdiv($ts, 30));

        self::assertTrue($totp->verify($secret, $code, 1, $ts));
        self::assertTrue($totp->verify($secret, $code, 1, $ts + 29), 'still valid within the same period');
        self::assertFalse($totp->verify($secret, '000000', 1, $ts), 'wrong code rejected');
    }

    /**
     * The RFC 6238 SHA-1 vector: the secret "12345678901234567890" is Base32
     * GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ, and T = 59 falls in counter 1, whose sample code is
     * 287082 (the last six digits of the RFC's 94287082).
     */
    public function testKnownVectorRfc6238Sha1(): void
    {
        $totp = new TotpService();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        self::assertSame('287082', $totp->codeForCounter($secret, 1));
    }
}
