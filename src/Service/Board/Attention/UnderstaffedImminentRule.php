<?php

namespace App\Service\Board\Attention;

use App\Service\Board\BoardContext;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * A shift about to start that is still short of people.
 *
 * This is the one item on the board a manager can usually still act on, which is why an entirely
 * unassigned shift outranks a partly filled one rather than being ranked by shortfall size.
 */
final class UnderstaffedImminentRule implements AttentionRule
{
    public function evaluate(BoardContext $context): array
    {
        $window = $context->settings->preStartWarnMinutes();
        $items = [];

        foreach ($context->shifts as $shift) {
            if ($shift->getStartsAt() <= $context->now) {
                continue;
            }

            $warnFrom = $shift->getStartsAt()->modify(\sprintf('-%d minutes', $window));
            if ($warnFrom > $context->now) {
                continue;
            }

            $needed = $context->neededFor($shift);
            $assigned = $context->assignedFor($shift);
            if ($needed === 0 || $assigned >= $needed) {
                continue;
            }

            $items[] = new AttentionItem(
                'understaffed_imminent',
                $assigned === 0 ? AttentionSeverity::Critical : AttentionSeverity::Serious,
                'shift:'.$shift->getUuid(),
                new TranslatableMessage('board.attention.understaffed_imminent.title', ['%shift%' => $shift->getTitle()]),
                new TranslatableMessage('board.attention.understaffed_imminent.detail', [
                    '%assigned%' => $assigned,
                    '%needed%' => $needed,
                ]),
                $warnFrom,
                ['route' => 'app_shift_staffing', 'params' => ['id' => (string) $shift->getUuid()]],
            );
        }

        return $items;
    }

    public function transitions(BoardContext $context): array
    {
        $window = $context->settings->preStartWarnMinutes();
        $moments = [];

        foreach ($context->shifts as $shift) {
            // Entering the warning window, and leaving it again when the shift starts.
            $moments[] = $shift->getStartsAt()->modify(\sprintf('-%d minutes', $window));
            $moments[] = $shift->getStartsAt();
        }

        return $moments;
    }
}
