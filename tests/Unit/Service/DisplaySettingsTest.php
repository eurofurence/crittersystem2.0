<?php

namespace App\Tests\Unit\Service;

use App\Service\DisplaySettings;
use App\Service\EventConfigStore;
use PHPUnit\Framework\TestCase;

final class DisplaySettingsTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function settings(array $config): DisplaySettings
    {
        $store = $this->createStub(EventConfigStore::class);
        $store->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null) => $config[$key] ?? $default,
        );

        return new DisplaySettings($store);
    }

    public function testConvertsStoredUtcToConfiguredTimezone(): void
    {
        $settings = $this->settings([
            EventConfigStore::KEY_TIMEZONE => 'Europe/Berlin',
            EventConfigStore::KEY_DATETIME_FORMAT => 'Y-m-d H:i',
        ]);

        // 12:00 UTC is 14:00 in Berlin during summer (CEST, +02:00).
        $utc = new \DateTimeImmutable('2026-06-21 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-06-21 14:00', $settings->formatDateTime($utc));
    }

    public function testFallsBackToDefaultsWhenUnset(): void
    {
        $settings = $this->settings([]);

        self::assertSame('UTC', $settings->timezone()->getName());
        self::assertSame(EventConfigStore::DEFAULT_DATE_FORMAT, $settings->dateFormat());
        self::assertSame(EventConfigStore::DEFAULT_TIME_FORMAT, $settings->timeFormat());
        self::assertSame(EventConfigStore::DEFAULT_DATETIME_FORMAT, $settings->dateTimeFormat());
    }

    public function testInvalidTimezoneFallsBackToDefault(): void
    {
        $settings = $this->settings([EventConfigStore::KEY_TIMEZONE => 'Not/AReal_Zone']);

        self::assertSame('UTC', $settings->timezone()->getName());
    }

    public function testNullRendersEmptyString(): void
    {
        self::assertSame('', $this->settings([])->formatDate(null));
    }

    public function testRespectsCustomFormats(): void
    {
        $settings = $this->settings([
            EventConfigStore::KEY_TIMEZONE => 'UTC',
            EventConfigStore::KEY_DATE_FORMAT => 'd/m/Y',
            EventConfigStore::KEY_TIME_FORMAT => 'H:i:s',
        ]);

        $dt = new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'));

        self::assertSame('02/01/2026', $settings->formatDate($dt));
        self::assertSame('03:04:05', $settings->formatTime($dt));
    }
}
