<?php

namespace App\Service;

use App\Entity\EventConfig;
use App\Repository\EventConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read/write access to the event_config key-value store
 *
 * Values are persisted as JSON; callers deal in plain PHP scalars/arrays.
 * Well-known keys are exposed as constants so other services can share them.
 */
class EventConfigStore
{
    public const KEY_NAME = 'event.name';
    public const KEY_WELCOME_MESSAGE = 'event.welcome_message';
    public const KEY_ACCESS_MODE = 'event.access_mode';
    public const KEY_BUILDUP_START = 'event.buildup_start';
    public const KEY_EVENT_START = 'event.event_start';
    public const KEY_EVENT_END = 'event.event_end';
    public const KEY_TEARDOWN_END = 'event.teardown_end';
    public const KEY_DEFAULT_THEME = 'theme.default';

    // Display / regional settings (§ Configuration). These control how dates and
    // times are rendered for everyone, server-side, regardless of the viewer's
    // browser locale or timezone. See {@see \App\Service\DisplaySettings}.
    public const KEY_TIMEZONE = 'display.timezone';
    public const KEY_DATE_FORMAT = 'display.date_format';
    public const KEY_TIME_FORMAT = 'display.time_format';
    public const KEY_DATETIME_FORMAT = 'display.datetime_format';

    public const DEFAULT_TIMEZONE = 'UTC';
    public const DEFAULT_DATE_FORMAT = 'D, d M Y';
    public const DEFAULT_TIME_FORMAT = 'H:i';
    public const DEFAULT_DATETIME_FORMAT = 'D, d M Y H:i';

    public const ACCESS_MODES = ['public', 'staff', 'admin'];

    public function __construct(
        private readonly EventConfigRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $config = $this->repository->findOneByKey($key);

        return $config?->getValue() ?? $default;
    }

    /**
     * Read a value stored as an ISO-8601 string back as a date, or null when the
     * key is unset/blank or the stored value cannot be parsed.
     */
    public function getDate(string $key): ?\DateTimeImmutable
    {
        $value = $this->get($key);
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            // Normalise to the *named* UTC zone. Stored values are ISO-8601 with
            // a "+00:00" offset, which would otherwise yield a DateTimeImmutable
            // whose timezone name is "+00:00" — that mismatches the UTC
            // model_timezone of the event-config form fields and makes Symfony
            // Form throw. setTimezone() keeps the instant and fixes the name.
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Queue a value for the given key. Call {@see flush()} to persist.
     */
    public function set(string $key, mixed $value): void
    {
        $config = $this->repository->findOneByKey($key);
        if ($config === null) {
            $this->em->persist(new EventConfig($key, $value));

            return;
        }

        $config->setValue($value);
    }

    public function flush(): void
    {
        $this->em->flush();
    }

    /**
     * All settings as a flat key => value map.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->repository->findAllAsMap();
    }
}
