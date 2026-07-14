<?php

namespace App\Enum;

/**
 * Publication state of a shift. Draft shifts are invisible to normal
 * browsing, application, notifications, and public APIs until published.
 */
enum ShiftState: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PUBLISHED => 'Published',
        };
    }

    public function isPublished(): bool
    {
        return $this === self::PUBLISHED;
    }
}
