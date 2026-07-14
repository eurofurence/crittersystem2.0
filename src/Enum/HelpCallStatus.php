<?php

namespace App\Enum;

/** Lifecycle of a Global Call for Help. */
enum HelpCallStatus: string
{
    case OPEN = 'open';
    case FILLED = 'filled';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function isActive(): bool
    {
        return $this === self::OPEN;
    }
}
