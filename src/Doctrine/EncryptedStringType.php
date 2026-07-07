<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Service\SecretCipher;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * A transparently encrypted text column: values are encrypted with
 * {@see SecretCipher} on write and decrypted on read, so secrets never touch
 * the database in clear text. Stored as TEXT because the authenticated
 * ciphertext envelope is longer than the plaintext.
 *
 * Registered as the `encrypted_string` DBAL type in config/packages/doctrine.yaml
 * and primed with the cipher in {@see \App\Kernel::boot()} (DBAL instantiates
 * types without the service container, so the dependency is injected statically).
 */
final class EncryptedStringType extends Type
{
    public const NAME = 'encrypted_string';

    private static ?SecretCipher $cipher = null;

    public static function setCipher(SecretCipher $cipher): void
    {
        self::$cipher = $cipher;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::cipher()->encrypt((string) $value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::cipher()->decrypt((string) $value);
    }

    private static function cipher(): SecretCipher
    {
        if (self::$cipher === null) {
            throw new \LogicException('EncryptedStringType has not been initialised with a SecretCipher.');
        }

        return self::$cipher;
    }
}
