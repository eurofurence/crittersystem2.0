<?php

namespace App\Service\Board;

use App\Service\Assignment\EventHoursGuard;
use App\Service\EventConfigStore;

/**
 * Operational thresholds for the live board, read once per request from the event config.
 *
 * Every value has a defined default, so a database that has never been near the operations screen
 * still produces a usable board rather than a division by zero or an empty forecast.
 *
 * The hours cap is deliberately not one of these: it is the event-wide recommendation the rest of
 * the app already warns against, and duplicating it here would let the board disagree with the
 * warning a volunteer sees when they sign up.
 */
final class BoardSettings
{
    public function __construct(
        private readonly EventConfigStore $config,
        private readonly EventHoursGuard $hours,
    ) {
    }

    public function preStartWarnMinutes(): int
    {
        return max(0, $this->config->getInt(
            EventConfigStore::KEY_BOARD_PRE_START_WARN_MIN,
            EventConfigStore::DEFAULT_BOARD_PRE_START_WARN_MIN,
        ));
    }

    public function maxContinuousMinutes(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BOARD_MAX_CONTINUOUS_MIN,
            EventConfigStore::DEFAULT_BOARD_MAX_CONTINUOUS_MIN,
        ));
    }

    public function maxSequentialMinutes(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BOARD_MAX_SEQUENTIAL_MIN,
            EventConfigStore::DEFAULT_BOARD_MAX_SEQUENTIAL_MIN,
        ));
    }

    public function unattendedMinutes(): int
    {
        return max(0, $this->config->getInt(
            EventConfigStore::KEY_BOARD_UNATTENDED_MIN,
            EventConfigStore::DEFAULT_BOARD_UNATTENDED_MIN,
        ));
    }

    /** Hours cap for the event, from the existing recommendation. Zero when no cap is configured. */
    public function hoursCap(): int
    {
        return max(0, $this->hours->recommendedMax());
    }

    /** Hours at which a volunteer is flagged as approaching the cap. Zero when there is no cap. */
    public function overworkWarnHours(): float
    {
        $fraction = $this->config->getFloat(
            EventConfigStore::KEY_BOARD_OVERWORK_WARN_FRACTION,
            EventConfigStore::DEFAULT_BOARD_OVERWORK_WARN_FRACTION,
        );

        return $this->hoursCap() * min(1.0, max(0.0, $fraction));
    }

    /**
     * Ascending hour boundaries for the load meter on a volunteer card.
     *
     * @return list<float>
     */
    public function cardBands(): array
    {
        return $this->bands(EventConfigStore::KEY_BOARD_CARD_BANDS, EventConfigStore::DEFAULT_BOARD_CARD_BANDS);
    }

    /**
     * Ascending hour boundaries for the workload distribution. Intentionally a different bucketing
     * from {@see cardBands()}: the meter measures one person against the cap, the distribution
     * describes the whole department.
     *
     * @return list<float>
     */
    public function workloadBands(): array
    {
        return $this->bands(EventConfigStore::KEY_BOARD_WORKLOAD_BANDS, EventConfigStore::DEFAULT_BOARD_WORKLOAD_BANDS);
    }

    public function comingWindowMinutes(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BOARD_COMING_WINDOW_MIN,
            EventConfigStore::DEFAULT_BOARD_COMING_WINDOW_MIN,
        ));
    }

    public function offDutyWindowMinutes(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BOARD_OFF_DUTY_WINDOW_MIN,
            EventConfigStore::DEFAULT_BOARD_OFF_DUTY_WINDOW_MIN,
        ));
    }

    public function forecastHorizonHours(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BOARD_FORECAST_HORIZON_HOURS,
            EventConfigStore::DEFAULT_BOARD_FORECAST_HORIZON_HOURS,
        ));
    }

    public function forecastStepHours(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BOARD_FORECAST_STEP_HOURS,
            EventConfigStore::DEFAULT_BOARD_FORECAST_STEP_HOURS,
        ));
    }

    public function activeStaffTopN(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BOARD_ACTIVE_STAFF_TOP_N,
            EventConfigStore::DEFAULT_BOARD_ACTIVE_STAFF_TOP_N,
        ));
    }

    public function staffPageSize(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BOARD_PAGE_SIZE_STAFF,
            EventConfigStore::DEFAULT_BOARD_PAGE_SIZE_STAFF,
        ));
    }

    public function shiftsPageSize(): int
    {
        return max(1, $this->config->getInt(
            EventConfigStore::KEY_BOARD_PAGE_SIZE_SHIFTS,
            EventConfigStore::DEFAULT_BOARD_PAGE_SIZE_SHIFTS,
        ));
    }

    /**
     * Parse a comma-separated boundary list, discarding anything unusable. A malformed setting falls
     * back to the default rather than producing a band set the templates cannot label.
     *
     * @return list<float>
     */
    private function bands(string $key, string $default): array
    {
        $bands = self::parseBands($this->config->getString($key, $default));

        return $bands !== [] ? $bands : self::parseBands($default);
    }

    /** @return list<float> */
    private static function parseBands(string $raw): array
    {
        $bands = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (is_numeric($part) && (float) $part > 0) {
                $bands[] = (float) $part;
            }
        }

        $bands = array_values(array_unique($bands));
        sort($bands);

        return $bands;
    }
}
