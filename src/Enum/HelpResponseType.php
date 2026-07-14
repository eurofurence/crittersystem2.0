<?php

namespace App\Enum;

/** A user's response to a Global Call for Help. */
enum HelpResponseType: string
{
    case ACCEPT = 'accept';
    case REFUSE = 'refuse';
}
