<?php

namespace App\Enum;

/**
 * The two independent department link types.
 */
enum RequestLinkType: string
{
    /** Collect/update the user's global Planning Availability. */
    case AVAILABILITY_REQUEST = 'availability_request';

    /** Open a filtered department shift view to apply to eligible shifts. */
    case SHIFT_INVITATION = 'shift_invitation';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABILITY_REQUEST => 'Availability request',
            self::SHIFT_INVITATION => 'Shift application invitation',
        };
    }
}
