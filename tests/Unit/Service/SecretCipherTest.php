<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SecretCipher;
use PHPUnit\Framework\TestCase;

final class SecretCipherTest extends TestCase
{
    private function cipher(): SecretCipher
    {
        return new SecretCipher(SecretCipher::generateKey());
    }

    public function testRoundTrip(): void
    {
        $cipher = $this->cipher();
        $secret = 'super-secret-api-key-123';

        $encrypted = $cipher->encrypt($secret);

        self::assertNotSame($secret, $encrypted);
        self::assertStringStartsWith('v1.', $encrypted);
        self::assertSame($secret, $cipher->decrypt($encrypted));
    }

    public function testEncryptingTwiceYieldsDifferentCiphertext(): void
    {
        $cipher = $this->cipher();

        self::assertNotSame($cipher->encrypt('x'), $cipher->encrypt('x'), 'A fresh nonce must be used each time.');
    }

    public function testAcceptsHexKey(): void
    {
        $hexKey = bin2hex(random_bytes(32));
        $cipher = new SecretCipher($hexKey);

        self::assertTrue($cipher->isConfigured());
        self::assertSame('hello', $cipher->decrypt($cipher->encrypt('hello')));
    }

    public function testAcceptsBase64PrefixedKey(): void
    {
        $cipher = new SecretCipher('base64:' . SecretCipher::generateKey());

        self::assertTrue($cipher->isConfigured());
    }

    public function testIsNotConfiguredWhenKeyEmpty(): void
    {
        self::assertFalse((new SecretCipher(''))->isConfigured());
    }

    public function testIsNotConfiguredWhenKeyWrongLength(): void
    {
        self::assertFalse((new SecretCipher(bin2hex(random_bytes(8))))->isConfigured());
    }

    public function testEncryptThrowsWithoutKey(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SecretCipher(''))->encrypt('x');
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $cipher = $this->cipher();
        $encrypted = $cipher->encrypt('value');

        // Flip a character in the encoded body.
        $tampered = substr($encrypted, 0, -2) . (str_ends_with($encrypted, 'a') ? 'b' : 'a') . substr($encrypted, -1);

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($tampered);
    }

    public function testForeignFormatIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cipher()->decrypt('not-our-envelope');
    }
}
