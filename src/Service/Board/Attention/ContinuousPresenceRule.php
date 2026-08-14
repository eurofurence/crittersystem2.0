<?php

namespace App\Service\Board\Attention;

use App\Service\Board\BoardContext;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Somebody who has been present without a break for too long.
 *
 * The application records no breaks - there is no break entity, column or screen - so a gap between
 * two presence spans is what counts as one here. That is why the rule measures the current
 * uninterrupted stretch rather than summing the day: stepping away and coming back resets it, which
 * is exactly the behaviour a break would have had.
 */
final class ContinuousPresenceRule implements AttentionRule
{
    public function evaluate(BoardContext $context): array
    {
        $limit = $context->settings->maxContinuousMinutes();
        $items = [];

        foreach ($context->users as $user) {
            $span = $context->openSpanFor($user);
            if ($span === null) {
                continue;
            }

            $minutes = $span->minutes($context->now);
            if ($minutes < $limit) {
                continue;
            }

            $items[] = new AttentionItem(
                'continuous_presence',
                $minutes >= $limit * 2 ? AttentionSeverity::Critical : AttentionSeverity::Serious,
                'user:'.$user->getUuid(),
                new TranslatableMessage('board.attention.continuous_presence.title', [
                    '%name%' => $user->getName(),
                    '%hours%' => self::hoursAndMinutes($minutes),
                ]),
                new TranslatableMessage('board.attention.continuous_presence.detail', [
                    '%limit%' => self::hoursAndMinutes($limit),
                ]),
                $span->startedAt,
            );
        }

        return $items;
    }

    public function transitions(BoardContext $context): array
    {
        $limit = $context->settings->maxContinuousMinutes();
        $moments = [];

        foreach ($context->users as $user) {
            $span = $context->openSpanFor($user);
            if ($span === null) {
                continue;
            }
            // When this stretch crosses the limit, and when it crosses twice the limit into critical.
            $moments[] = $span->startedAt->modify(\sprintf('+%d minutes', $limit));
            $moments[] = $span->startedAt->modify(\sprintf('+%d minutes', $limit * 2));
        }

        return $moments;
    }

    private static function hoursAndMinutes(int $minutes): string
    {
        return \sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
