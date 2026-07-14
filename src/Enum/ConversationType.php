<?php

namespace App\Enum;

/**
 * The kind of conversation. A support conversation is a user talking
 * to the Info Desk Team (claimed by an Info Desk member); a direct conversation
 * is a staff member reaching a specific user.
 */
enum ConversationType: string
{
    case SUPPORT = 'support';
    case DIRECT = 'direct';
}
