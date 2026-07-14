<?php

namespace App\Enum;

/**
 * Lifecycle of an automatic assignment proposal. A proposal is
 * never published automatically; a manager applies or discards it.
 */
enum ProposalStatus: string
{
    case DRAFT = 'draft';
    case APPLIED = 'applied';
    case DISCARDED = 'discarded';
}
