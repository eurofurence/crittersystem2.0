<?php

namespace App\Service\Board\Attention;

/**
 * Ranking for the attention list. Higher {@see weight()} sorts first, so the most severe item is the
 * one a manager reads without looking further down the panel.
 */
enum AttentionSeverity: string
{
    case Good = 'good';
    case Warning = 'warning';
    case Serious = 'serious';
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::Serious => 3,
            self::Warning => 2,
            self::Good => 1,
        };
    }

    /** Paired with the accent the template picks, so the severity is never carried by colour alone. */
    public function icon(): string
    {
        return match ($this) {
            self::Critical => 'alert-triangle',
            self::Serious => 'alert-triangle',
            self::Warning => 'clock',
            self::Good => 'check',
        };
    }
}
