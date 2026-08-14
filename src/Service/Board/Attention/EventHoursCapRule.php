<?php

namespace App\Service\Board\Attention;

use App\Service\Board\BoardContext;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Somebody at or near the event-wide hours recommendation.
 *
 * The figure is the credited-hours total the rest of the application already shows and already warns
 * against when a volunteer signs up, so the board agrees with the warning they saw. It is a
 * recommendation and not a limit: an item here says somebody should be looked at, never that
 * anything is blocked.
 */
final class EventHoursCapRule implements AttentionRule
{
    public function evaluate(BoardContext $context): array
    {
        $cap = $context->settings->hoursCap();
        if ($cap <= 0) {
            return [];
        }

        $warnAt = $context->settings->overworkWarnHours();
        $items = [];

        foreach ($context->users as $user) {
            $hours = $context->totalHoursFor($user);
            if ($hours < $warnAt) {
                continue;
            }

            $items[] = new AttentionItem(
                'event_hours_cap',
                $hours >= $cap ? AttentionSeverity::Critical : AttentionSeverity::Warning,
                'user:'.$user->getUuid(),
                new TranslatableMessage(
                    $hours >= $cap ? 'board.attention.hours_over.title' : 'board.attention.hours_near.title',
                    ['%name%' => $user->getName()],
                ),
                new TranslatableMessage('board.attention.hours.detail', [
                    '%hours%' => number_format($hours, 1),
                    '%cap%' => $cap,
                ]),
                // Hours accrue with the schedule rather than at an instant, so the day is the most
                // honest "since" available; it keeps the ranking stable within a board.
                $context->dayStart,
            );
        }

        return $items;
    }

    /**
     * Credited hours come from the schedule, so this rule changes only when an assignment changes -
     * which publishes a signal of its own. Nothing to declare.
     */
    public function transitions(BoardContext $context): array
    {
        return [];
    }
}
