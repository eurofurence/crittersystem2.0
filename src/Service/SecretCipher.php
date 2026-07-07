<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Authenticated symmetric encryption for secrets stored at rest (SSO client
 * secret, Telegram API key, the digital certificate private key, ...).
 *
 * Uses libsodium's secretbox (XSalsa20-Poly1305): every value is encrypted with
 * a fresh random nonce and authenticated, so tampering is detected on read. The
 * key is supplied out-of-band via the APP_ENCRYPTION_KEY environment variable
 * (wired to the deployment secret store in production); it is never persisted.
 *
 * Accepted key formats: a 32-byte key encoded as base64 (optionally prefixed
 * with "base64:") or as 64 hex characters. Generate one with
 * `php bin/console app:encryption:generate-key`.
 */
final class SecretCipher
{
    /** Versioned envelope marker so the format can evolve without ambiguity. */
    private const PREFIX = 'v1.';

    private ?string $resolvedKey = null;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $encryptionKey,
    ) {
    }

    /** True when a usable 32-byte key is configured. Never throws. */
    public function isConfigured(): bool
    {
        try {
            $this->key();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        return self::PREFIX . sodium_bin2base64($nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function decrypt(string $ciphertext): string
    {
        if (!str_starts_with($ciphertext, self::PREFIX)) {
            throw new \RuntimeException('Unrecognised ciphertext envelope.');
        }

        $bin = sodium_base642bin(substr($ciphertext, \strlen(self::PREFIX)), SODIUM_BASE64_VARIANT_ORIGINAL);
        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (\strlen($bin) <= $nonceLen) {
            throw new \RuntimeException('Ciphertext is too short to be valid.');
        }

        $nonce = substr($bin, 0, $nonceLen);
        $cipher = substr($bin, $nonceLen);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed: wrong key or corrupted data.');
        }

        return $plain;
    }

    /** Generate a fresh base64-encoded key suitable for APP_ENCRYPTION_KEY. */
    public static function generateKey(): string
    {
        return sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    private function key(): string
    {
        if ($this->resolvedKey !== null) {
            return $this->resolvedKey;
        }

        $raw = trim($this->encryptionKey);
        if ($raw === '') {
            throw new \RuntimeException('APP_ENCRYPTION_KEY is not set.');
        }
        if (str_starts_with($raw, 'base64:')) {
            $raw = substr($raw, 7);
        }

        if (preg_match('/^[0-9a-fA-F]{64}$/', $raw) === 1) {
            $bin = sodium_hex2bin($raw);
        } else {
            $bin = base64_decode($raw, true);
            if ($bin === false) {
                throw new \RuntimeException('APP_ENCRYPTION_KEY is neither valid base64 nor 64 hex characters.');
            }
        }

        if (\strlen($bin) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(\sprintf(
                'APP_ENCRYPTION_KEY must decode to %d bytes, got %d.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                \strlen($bin),
            ));
        }

        return $this->resolvedKey = $bin;
    }
}
