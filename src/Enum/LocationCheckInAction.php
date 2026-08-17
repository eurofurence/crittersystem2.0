<?php

namespace App\Enum;

/**
 * What a location check-in row records.
 *
 * The log is append-only, so withdrawing an entry writes a second row rather than deleting the
 * first. Somebody who entered, was sent away and came back is three rows, and the daily report can
 * still say what happened.
 */
enum LocationCheckInAction: string
{
    /** The person was admitted to the venue. */
    case ENTERED = 'entered';

    /** A previous entry was taken back, by mistake or because the person left. */
    case WITHDRAWN = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::ENTERED => 'Checked in',
            self::WITHDRAWN => 'Check-in withdrawn',
        };
    }
}
