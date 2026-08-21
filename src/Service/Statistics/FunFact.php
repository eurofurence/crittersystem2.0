<?php

namespace App\Service\Statistics;

/**
 * One tile in the fun section: a big number, what it means, and the assumption it rests on.
 *
 * The basis line is not decoration. Every derived comparison here multiplies a real total by an
 * estimate, and the figure gets read out to an audience, so the assumption travels with the number
 * instead of living only in the source.
 */
final readonly class FunFact
{
    /**
     * @param string|null           $captionKey    translation key, null when $literalCaption is used
     * @param array<string, string> $captionParams
     * @param array<string, string> $basisParams
     * @param string|null           $literalCaption free text typed by an admin, never translated
     */
    public function __construct(
        public string $icon,
        public float $value,
        public int $precision = 0,
        public ?string $captionKey = null,
        public array $captionParams = [],
        public ?string $basisKey = null,
        public array $basisParams = [],
        public ?string $literalCaption = null,
    ) {
    }
}
