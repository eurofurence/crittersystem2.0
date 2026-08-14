<?php

namespace App\Service\Board\Attention;

use App\Service\Board\BoardContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One kind of thing worth flagging on the board.
 *
 * Implementations are collected by the container, so adding a rule is adding a class - the builder
 * neither names them nor needs changing. Each reads its own threshold from the board settings, and
 * each must be able to run against any day, not only today.
 */
#[AutoconfigureTag]
interface AttentionRule
{
    /** @return list<AttentionItem> */
    public function evaluate(BoardContext $context): array;

    /**
     * Instants strictly after "now" at which this rule's verdict could change without anything
     * happening in the application.
     *
     * The board has no heartbeat: it re-renders at the exact moment its content changes, and this is
     * how each rule declares those moments. A rule that can become true purely with the passage of
     * time and returns nothing here will simply not surface until the next unrelated change.
     *
     * @return list<\DateTimeImmutable>
     */
    public function transitions(BoardContext $context): array;
}
