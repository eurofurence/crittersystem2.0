<?php

namespace App\Enum;

/** Conversation lifecycle state. */
enum ConversationStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}
