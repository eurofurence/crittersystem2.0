<?php

namespace App\Enum;

/**
 * A user's declared willingness to work in a time range. Ordered
 * from most to least willing, which the automatic proposal ranks by.
 */
enum AvailabilityValue: string
{
    /** Wants to work in this range. */
    case PREFERRED = 'preferred';

    /** Can work in this range. */
    case AVAILABLE = 'available';

    /** Can work if necessary but prefers not to. */
    case AVOID = 'avoid';

    /** Cannot work without a manager override. */
    case UNAVAILABLE = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::PREFERRED => 'Preferred',
            self::AVAILABLE => 'Available',
            self::AVOID => 'Avoid',
            self::UNAVAILABLE => 'Unavailable',
        };
    }

    /** Lower is more willing - used to rank soft constraints. */
    public function rank(): int
    {
        return match ($this) {
            self::PREFERRED => 0,
            self::AVAILABLE => 1,
            self::AVOID => 2,
            self::UNAVAILABLE => 3,
        };
    }

    /** Whether assigning here needs an explicit manager override. */
    public function needsOverride(): bool
    {
        return $this === self::AVOID || $this === self::UNAVAILABLE;
    }
}
