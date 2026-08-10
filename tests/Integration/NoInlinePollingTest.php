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
     * @return string[] the body of every inline <script> in the template
     */
    private function inlineScripts(string $contents): array
    {
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#si', $contents, $matches);

        return $matches[1];
    }
}
