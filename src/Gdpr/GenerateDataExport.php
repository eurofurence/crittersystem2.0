<?php

declare(strict_types=1);

namespace App\Gdpr;

/** Message: build the data-portability archive for a queued DataExport. */
final readonly class GenerateDataExport
{
    public function __construct(public int $exportId)
    {
    }
}
