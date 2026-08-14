<?php

namespace App\Service\Board\Attention;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * One thing on the board that needs somebody's attention.
 *
 * `title` and `detail` are translatable messages rather than strings: the board is rendered in the
 * viewer's locale, and the rules that build these run far from any template.
 */
final class AttentionItem
{
    /**
     * @param string                   $type    rule identifier, also the de-duplication key with $subjectKey
     * @param array{route: string, params: array<string, mixed>}|null $link where the subject can be opened
     */
    public function __construct(
        public readonly string $type,
        public readonly AttentionSeverity $severity,
        public readonly string $subjectKey,
        public readonly TranslatableMessage $title,
        public readonly TranslatableMessage $detail,
        public readonly \DateTimeImmutable $since,
        public readonly ?array $link = null,
    ) {
    }

    /**
     * Most severe first, then oldest first so a problem that has been open longest outranks one that
     * has only just appeared at the same severity.
     *
     * @param list<self> $items
     *
     * @return list<self>
     */
    public static function rank(array $items): array
    {
        usort($items, static function (self $a, self $b): int {
            return $b->severity->weight() <=> $a->severity->weight()
                ?: $a->since <=> $b->since;
        });

        return $items;
    }

    /**
     * One item per subject per rule. Two rules may both have something to say about the same
     * volunteer, and both are worth showing; the same rule firing twice for them is not.
     *
     * @param list<self> $items
     *
     * @return list<self>
     */
    public static function deduplicate(array $items): array
    {
        $seen = [];
        foreach ($items as $item) {
            $key = $item->type.'|'.$item->subjectKey;
            if (!isset($seen[$key])) {
                $seen[$key] = $item;
            }
        }

        return array_values($seen);
    }
}
