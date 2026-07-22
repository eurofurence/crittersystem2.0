<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AppVersion;
use PHPUnit\Framework\TestCase;

/**
 * Protects how the deployed version string is resolved for display: the baked
 * VERSION file wins, a source checkout falls back to the running git HEAD, and
 * a build with neither still yields a safe placeholder.
 */
final class AppVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/appversion-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testTheBakedVersionFileIsUsedWhenPresent(): void
    {
        file_put_contents($this->dir . '/VERSION', "v2.3.4\n");

        self::assertSame('v2.3.4', (new AppVersion($this->dir))->get());
    }

    public function testTheVersionFileTakesPrecedenceOverGit(): void
    {
        file_put_contents($this->dir . '/VERSION', '1a2b3c4');
        mkdir($this->dir . '/.git');
        file_put_contents($this->dir . '/.git/HEAD', 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');

        self::assertSame('1a2b3c4', (new AppVersion($this->dir))->get());
    }

    public function testItFallsBackToTheBranchGitHead(): void
    {
        mkdir($this->dir . '/.git');
        mkdir($this->dir . '/.git/refs', 0o777, true);
        mkdir($this->dir . '/.git/refs/heads');
        file_put_contents($this->dir . '/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->dir . '/.git/refs/heads/main', "abcdef1234567890abcdef1234567890abcdef12\n");

        self::assertSame('abcdef1', (new AppVersion($this->dir))->get());
    }

    public function testItFallsBackToADetachedGitHead(): void
    {
        mkdir($this->dir . '/.git');
        file_put_contents($this->dir . '/.git/HEAD', "0123456789abcdef0123456789abcdef01234567\n");

        self::assertSame('0123456', (new AppVersion($this->dir))->get());
    }

    public function testItYieldsAPlaceholderWhenNothingIsAvailable(): void
    {
        self::assertSame('dev', (new AppVersion($this->dir))->get());
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
