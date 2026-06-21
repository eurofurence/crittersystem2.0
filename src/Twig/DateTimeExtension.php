<?php

namespace App\Twig;

use App\Service\DisplaySettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig filters that render dates/times using the globally configured display
 * settings (timezone + formats). Prefer these over the built-in `|date` filter
 * so admins can control how dates appear site-wide via /manage/configuration.
 *
 *   {{ shift.startsAt|app_datetime }}   {{ shift.startsAt|app_time }}
 *   {{ news.publishedAt|app_date }}
 */
final class DateTimeExtension extends AbstractExtension
{
    public function __construct(private readonly DisplaySettings $settings)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('app_date', $this->date(...)),
            new TwigFilter('app_time', $this->time(...)),
            new TwigFilter('app_datetime', $this->dateTime(...)),
        ];
    }

    public function date(mixed $value): string
    {
        return $this->settings->formatDate($this->coerce($value));
    }

    public function time(mixed $value): string
    {
        return $this->settings->formatTime($this->coerce($value));
    }

    public function dateTime(mixed $value): string
    {
        return $this->settings->formatDateTime($this->coerce($value));
    }

    private function coerce(mixed $value): ?\DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        if (\is_int($value)) {
            return (new \DateTimeImmutable())->setTimestamp($value);
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return null;
        }
    }
}
