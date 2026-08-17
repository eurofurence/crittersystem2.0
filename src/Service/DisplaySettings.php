<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Resolves the global display settings (timezone + date/time formats) and uses
 * them to render dates and times consistently for every viewer.
 *
 * Rendering is server-side, so the output is the same regardless of the viewer's
 * browser locale or operating-system timezone. Stored datetimes are UTC (the
 * PHP/Doctrine default in this app); they are converted to the configured
 * display timezone here before formatting.
 *
 * Values are resolved once per request and memoised.
 */
class DisplaySettings
{
    private ?\DateTimeZone $timezone = null;

    public function __construct(
        private readonly EventConfigStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * An unusable configured zone is logged at error level: the fallback silently renders every
     * date in the application in a zone the administrator did not choose.
     */
    public function timezone(): \DateTimeZone
    {
        if ($this->timezone === null) {
            $name = (string) $this->store->get(EventConfigStore::KEY_TIMEZONE, EventConfigStore::DEFAULT_TIMEZONE);
            try {
                $this->timezone = new \DateTimeZone($name !== '' ? $name : EventConfigStore::DEFAULT_TIMEZONE);
            } catch (\Exception $e) {
                $this->logger->error('Configured timezone "{name}" is not usable; falling back to {fallback}: {reason}', [
                    'name' => $name,
                    'fallback' => EventConfigStore::DEFAULT_TIMEZONE,
                    'reason' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $this->timezone = new \DateTimeZone(EventConfigStore::DEFAULT_TIMEZONE);
            }
        }

        return $this->timezone;
    }

    public function dateFormat(): string
    {
        return $this->formatOrDefault(EventConfigStore::KEY_DATE_FORMAT, EventConfigStore::DEFAULT_DATE_FORMAT);
    }

    public function timeFormat(): string
    {
        return $this->formatOrDefault(EventConfigStore::KEY_TIME_FORMAT, EventConfigStore::DEFAULT_TIME_FORMAT);
    }

    public function dateTimeFormat(): string
    {
        return $this->formatOrDefault(EventConfigStore::KEY_DATETIME_FORMAT, EventConfigStore::DEFAULT_DATETIME_FORMAT);
    }

    public function formatDate(?\DateTimeInterface $value): string
    {
        return $this->apply($value, $this->dateFormat());
    }

    public function formatTime(?\DateTimeInterface $value): string
    {
        return $this->apply($value, $this->timeFormat());
    }

    public function formatDateTime(?\DateTimeInterface $value): string
    {
        return $this->apply($value, $this->dateTimeFormat());
    }

    private function apply(?\DateTimeInterface $value, string $format): string
    {
        if ($value === null) {
            return '';
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone($this->timezone())
            ->format($format);
    }

    private function formatOrDefault(string $key, string $default): string
    {
        $value = $this->store->get($key, $default);

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
