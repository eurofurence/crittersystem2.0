<?php

namespace App\Tests\Integration;

use App\Service\Board\BoardSettings;
use App\Service\EventConfigStore;
use App\Tests\DatabaseTestCase;

/**
 * A database that has never been near the operations screen still has to produce a usable board,
 * and a threshold typed in wrongly must not take the board down with it.
 */
final class BoardSettingsTest extends DatabaseTestCase
{
    private function settings(): BoardSettings
    {
        return static::getContainer()->get(BoardSettings::class);
    }

    private function store(): EventConfigStore
    {
        return static::getContainer()->get(EventConfigStore::class);
    }

    public function testFallsBackToTheDocumentedDefaults(): void
    {
        $settings = $this->settings();

        self::assertSame(15, $settings->preStartWarnMinutes());
        self::assertSame(360, $settings->maxContinuousMinutes());
        self::assertSame(360, $settings->maxSequentialMinutes());
        self::assertSame(10, $settings->unattendedMinutes());
        self::assertSame(60, $settings->comingWindowMinutes());
        self::assertSame(60, $settings->offDutyWindowMinutes());
        self::assertSame(5, $settings->forecastHorizonHours());
        self::assertSame(1, $settings->forecastStepHours());
        self::assertSame(8, $settings->activeStaffTopN());
        self::assertSame([15.0, 20.0, 25.0], $settings->cardBands());
        self::assertSame([10.0, 20.0, 30.0], $settings->workloadBands());
    }

    /** The warning threshold is a fraction of the event-wide recommendation, never a second copy of it. */
    public function testOverworkWarningTracksTheConfiguredHoursCap(): void
    {
        $this->store()->set(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, 20);
        $this->store()->flush();

        self::assertSame(20, $this->settings()->hoursCap());
        self::assertEqualsWithDelta(18.0, $this->settings()->overworkWarnHours(), 0.001);

        $this->store()->set(EventConfigStore::KEY_HOURS_RECOMMENDED_MAX, 30);
        $this->store()->flush();

        self::assertSame(30, $this->settings()->hoursCap());
        self::assertEqualsWithDelta(27.0, $this->settings()->overworkWarnHours(), 0.001);
    }

    public function testBandsAreSortedAndDeduplicated(): void
    {
        $this->store()->set(EventConfigStore::KEY_BOARD_CARD_BANDS, '25, 15,20 ,15');
        $this->store()->flush();

        self::assertSame([15.0, 20.0, 25.0], $this->settings()->cardBands());
    }

    /** An unusable band list falls back to the default rather than leaving the chart unlabelled. */
    public function testMalformedBandsFallBackToTheDefault(): void
    {
        $this->store()->set(EventConfigStore::KEY_BOARD_WORKLOAD_BANDS, 'soon, later');
        $this->store()->flush();

        self::assertSame([10.0, 20.0, 30.0], $this->settings()->workloadBands());
    }

    /** Zero or negative windows would produce an empty forecast or a division by zero downstream. */
    public function testNonPositiveValuesAreClampedToSomethingUsable(): void
    {
        $this->store()->set(EventConfigStore::KEY_BOARD_FORECAST_STEP_HOURS, 0);
        $this->store()->set(EventConfigStore::KEY_BOARD_ACTIVE_STAFF_TOP_N, -4);
        $this->store()->set(EventConfigStore::KEY_BOARD_PRE_START_WARN_MIN, -10);
        $this->store()->flush();

        self::assertSame(1, $this->settings()->forecastStepHours());
        self::assertSame(1, $this->settings()->activeStaffTopN());
        self::assertSame(0, $this->settings()->preStartWarnMinutes());
    }
}
