<?php

namespace App\Service\Statistics;

/**
 * The real-world counts an event tallies by hand, because nothing in the application records them.
 *
 * Each entry is optional. A blank figure is hidden on the dashboard rather than shown as zero, so
 * an event that never counted its coffee simply does not mention coffee.
 *
 * Adding an entry here makes it appear on the admin form and, once filled in, on the dashboard.
 * Removing one hides it but leaves any stored figure untouched, so a slug can be reinstated without
 * losing last year's number.
 */
final class TallyCatalog
{
    /**
     * Slug => emoji. Slugs are stored in the event configuration and must stay stable; renaming one
     * orphans the figure an admin already typed in.
     */
    public const ENTRIES = [
        'coffee' => '☕',
        'energy_drinks' => '🥤',
        'water' => '💧',
        'meals' => '🍽️',
        'pizza' => '🍕',
        'sweets' => '🍬',
        'batteries' => '🔋',
        'radios' => '📻',
        'cable' => '🔌',
        'plasters' => '🩹',
        'stickers' => '🏷️',
        'hugs' => '🤗',
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::ENTRIES);
    }

    public static function icon(string $slug): string
    {
        return self::ENTRIES[$slug] ?? '✨';
    }

    public static function has(string $slug): bool
    {
        return \array_key_exists($slug, self::ENTRIES);
    }
}
