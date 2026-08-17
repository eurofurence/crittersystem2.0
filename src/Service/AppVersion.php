<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Resolves the deployed software version for display (e.g. on /home).
 *
 * Production images bake a VERSION file at the project root at build time (the
 * git tag when built from a tag, otherwise the short commit hash). When that
 * file is absent, as in a source checkout, the running git HEAD is read
 * directly so developers still see a meaningful value.
 */
final class AppVersion
{
    private ?string $cached = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function get(): string
    {
        return $this->cached ??= $this->resolve();
    }

    private function resolve(): string
    {
        $file = $this->projectDir . '/VERSION';
        if (is_file($file)) {
            $value = trim((string) file_get_contents($file));
            if ($value !== '') {
                return $value;
            }
        }

        return $this->fromGitHead() ?? 'dev';
    }

    /**
     * `.git/HEAD` holds either a commit hash or `ref: refs/heads/<branch>`; in the second case the
     * hash lives in the file that ref names, so it has to be followed.
     */
    private function fromGitHead(): ?string
    {
        $head = $this->projectDir . '/.git/HEAD';
        if (!is_file($head)) {
            return null;
        }

        $content = trim((string) file_get_contents($head));
        if ($content === '') {
            return null;
        }

        if (str_starts_with($content, 'ref:')) {
            $ref = trim(substr($content, 4));
            $refFile = $this->projectDir . '/.git/' . $ref;
            if (!is_file($refFile)) {
                return null;
            }
            $content = trim((string) file_get_contents($refFile));
        }

        return $content !== '' ? substr($content, 0, 7) : null;
    }
}
