<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;

/**
 * Stores every {@see \DateTimeImmutable} as a "TIMESTAMP WITH TIME ZONE"
 * (PostgreSQL `timestamptz`) column, with the value always normalised to UTC
 * before it is written and again after it is read.
 *
 * This guarantees a single, unambiguous instant for every timestamp in the
 * database regardless of the PHP timezone of the value handed to Doctrine -
 * important for audit and compliance. The application converts to/from the
 * configured display timezone only at the edges (see {@see \App\Service\DisplaySettings}).
 *
 * Registered as an override of the built-in `datetime_immutable` type in
 * config/packages/doctrine.yaml, so all entity datetime columns use it without
 * any per-field annotations.
 */
final class UtcDateTimeImmutableType extends DateTimeTzImmutableType
{
    private static ?\DateTimeZone $utc = null;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        return parent::convertToPHPValue($value, $platform)?->setTimezone(self::utc());
    }

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
