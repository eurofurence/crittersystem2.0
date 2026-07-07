<?php

declare(strict_types=1);

namespace App\TwoFactor;

/**
 * Time-based one-time passwords (RFC 6238 / HOTP RFC 4226), dependency-free.
 * Secrets are Base32 strings compatible with standard authenticator apps.
 */
final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        for ($i = 0; $i < $length; ++$i) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public function provisioningUri(string $accountName, string $issuer, string $secret): string
    {
        $label = rawurlencode($issuer.':'.$accountName);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /** Verify a code against the current time window (±$window periods for clock drift). */
    public function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (\strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = (int) floor(($timestamp ?? time()) / self::PERIOD);
        for ($i = -$window; $i <= $window; ++$i) {
            if (hash_equals($this->codeForCounter($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public function codeForCounter(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);

        $offset = \ord($hash[19]) & 0x0F;
        $value = ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim($secret, '='));
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $index;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= \chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
