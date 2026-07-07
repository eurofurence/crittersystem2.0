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

    public function testKnownVectorRfc6238Sha1(): void
    {
        // RFC 6238 test secret "12345678901234567890" => Base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
        $totp = new TotpService();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        // T = 59 -> counter 1 -> RFC sample code 287082 (last 6 of 94287082).
        self::assertSame('287082', $totp->codeForCounter($secret, 1));
    }
}
