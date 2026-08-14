<?php

namespace App\Service\Board\Attention;

use App\Service\Board\BoardContext;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * A shift that is running and staffed on paper, but nobody assigned to it is actually present.
 *
 * This is the only rule that can tell a manager a position has quietly gone unmanned, and it depends
 * entirely on presence being recorded: with no duty session and no check-in against the shift, an
 * assignee is indistinguishable from an absent one. A shift nobody is assigned to is not reported
 * here - that is understaffing, and the understaffed rules already say so.
 */
final class UnattendedPositionRule implements AttentionRule
{
    public function evaluate(BoardContext $context): array
    {
        $tolerance = $context->settings->unattendedMinutes();
        $items = [];

        foreach ($context->shifts as $shift) {
            if (!$context->isRunning($shift)) {
                continue;
            }

            $unattendedFrom = $shift->getStartsAt()->modify(\sprintf('+%d minutes', $tolerance));
            if ($unattendedFrom > $context->now) {
                continue;
            }

            $assigned = $context->assignedFor($shift);
            if ($assigned === 0 || $context->presentOn($shift) > 0) {
                continue;
            }

            $minutes = intdiv($context->now->getTimestamp() - $unattendedFrom->getTimestamp(), 60);

            $items[] = new AttentionItem(
                'unattended_position',
                $minutes >= $tolerance * 2 ? AttentionSeverity::Critical : AttentionSeverity::Serious,
                'shift:'.$shift->getUuid(),
                new TranslatableMessage('board.attention.unattended.title', ['%shift%' => $shift->getTitle()]),
                new TranslatableMessage('board.attention.unattended.detail', ['%assigned%' => $assigned]),
                $unattendedFrom,
                ['route' => 'app_shift_staffing', 'params' => ['id' => (string) $shift->getUuid()]],
            );
        }

        return $items;
    }

    public function transitions(BoardContext $context): array
    {
        $tolerance = $context->settings->unattendedMinutes();
        $moments = [];

        foreach ($context->shifts as $shift) {
            $moments[] = $shift->getStartsAt()->modify(\sprintf('+%d minutes', $tolerance));
            $moments[] = $shift->getStartsAt()->modify(\sprintf('+%d minutes', $tolerance * 2));
            $moments[] = $shift->getEndsAt();
        }

        return $moments;
    }
}
