<?php

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * No template may drive a repeating request from an inline script.
 *
 * A `setTimeout` that reschedules itself inside a `<script>` tag has no owner: Turbo replaces the
 * body without tearing the document down, so the loop keeps running after the user has navigated
 * away and a second one starts when they come back. That is how the Telegram link step ended up
 * polling several times a second from pages that had nothing to do with it.
 *
 * A Stimulus controller has `disconnect()`, which Turbo does call, so the timer dies with the
 * element that owns it. Anything that repeats belongs in one.
 */
final class NoInlinePollingTest extends TestCase
{
    /**
     * The install wizard is the one place this is tolerated, and only because none of the reasoning
     * above applies to it: it is a one-shot page shown before the application exists, it is not
     * navigated by Turbo, and its loop ends by itself when the migration reports ok or failed.
     * Converting it would put deployment-critical code at risk for no behavioural gain.
     */
    private const TOLERATED = ['install/database.html.twig'];

    public function testNoTemplateSchedulesARepeatingRequestInline(): void
    {
        $offenders = [];
        $root = \dirname(__DIR__, 2).'/templates';

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
            if (\in_array($relative, self::TOLERATED, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            foreach ($this->inlineScripts($contents) as $script) {
                if (preg_match('/\b(setTimeout|setInterval)\b/', $script) === 1) {
                    $offenders[] = $relative;
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offenders)),
            'move the timer into a Stimulus controller so disconnect() can stop it',
        );
    }

    /**
     * A `<meta http-equiv="refresh">` is the same bug without a script tag, and it is worse.
     *
     * The refresh is scheduled on the document against the URL that was current when the tag was
     * parsed. Turbo navigates by swapping the body, never by loading a document, so the browser has
     * no reason to cancel it and dropping the tag from the head does not either. The timer fires
     * long after the user has moved on and pulls them back to the page that set it.
     *
     * A region that has to change on a clock declares `data-next-transition` and lets the
     * live-stream controller re-fetch it once, because that timer dies with the element.
     */
    public function testNoTemplateSchedulesADocumentRefresh(): void
    {
        $offenders = [];
        $root = \dirname(__DIR__, 2).'/templates';

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }

            $contents = $this->withoutComments((string) file_get_contents($file->getPathname()));
            if (preg_match('/http-equiv\s*=\s*["\']?refresh/i', $contents) === 1) {
                $offenders[] = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offenders)),
            'declare data-next-transition on the fragment and let the live-stream controller refetch it',
        );
    }

    /**
     * The rule these tests carry is explained in the templates that had to work around it, so the
     * banned markup appears in their comments. Only what the browser receives is an offence.
     */
    private function withoutComments(string $contents): string
    {
        return (string) preg_replace(['/\{#.*?#\}/s', '/<!--.*?-->/s'], '', $contents);
    }

    /**
     * @return string[] the body of every inline <script> in the template
     */
    private function inlineScripts(string $contents): array
    {
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#si', $contents, $matches);

        return $matches[1];
    }
}
