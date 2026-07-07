<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitises rich-text HTML coming from the reusable editor before it is stored
 * or rendered, stripping scripts/event handlers and unsafe URLs while keeping
 * common formatting. Shared by every feature that uses the editor.
 */
final class RichTextSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowLinkSchemes(['https', 'http', 'mailto']);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
