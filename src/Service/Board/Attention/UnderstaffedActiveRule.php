<?php

namespace App\Service\Board\Attention;

use App\Service\Board\BoardContext;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * A shift that is running right now with fewer people assigned than it asks for.
 *
 * Severity follows how much of the shift is uncovered rather than the raw shortfall: being one short
 * of two is a bigger operational problem than being one short of twelve.
 */
final class UnderstaffedActiveRule implements AttentionRule
{
    public function evaluate(BoardContext $context): array
    {
        $items = [];

        foreach ($context->shifts as $shift) {
            if (!$context->isRunning($shift)) {
                continue;
            }

            $needed = $context->neededFor($shift);
            $assigned = $context->assignedFor($shift);
            if ($needed === 0 || $assigned >= $needed) {
                continue;
            }

            $shortfall = ($needed - $assigned) / $needed;
            $severity = match (true) {
                $assigned === 0 => AttentionSeverity::Critical,
                $shortfall >= 0.5 => AttentionSeverity::Serious,
                default => AttentionSeverity::Warning,
            };

            $items[] = new AttentionItem(
                'understaffed_active',
                $severity,
                'shift:'.$shift->getUuid(),
                new TranslatableMessage('board.attention.understaffed_active.title', ['%shift%' => $shift->getTitle()]),
                new TranslatableMessage('board.attention.understaffed_active.detail', [
                    '%assigned%' => $assigned,
                    '%needed%' => $needed,
                ]),
                $shift->getStartsAt(),
                ['route' => 'app_shift_staffing', 'params' => ['id' => (string) $shift->getUuid()]],
            );
        }

        return $items;
    }

    public function transitions(BoardContext $context): array
    {
        $moments = [];
        foreach ($context->shifts as $shift) {
            $moments[] = $shift->getStartsAt();
            $moments[] = $shift->getEndsAt();
        }

        return $moments;
    }
}
