<?php

namespace App\Tests\Unit\Service\Install;

use App\Service\Install\InstallStateStore;
use PHPUnit\Framework\TestCase;

final class InstallStateStoreTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/critter-install-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var', 0775, true);
    }

    protected function tearDown(): void
    {
        $dir = $this->projectDir;
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($dir);
        }
    }

    private function store(): InstallStateStore
    {
        return new InstallStateStore($this->projectDir);
    }

    public function testStatusStartsIdle(): void
    {
        self::assertSame(InstallStateStore::STATE_IDLE, $this->store()->readStatus()['state']);
    }

    public function testMigrationLifecycleAndLog(): void
    {
        $store = $this->store();

        $store->beginMigration();
        self::assertSame(InstallStateStore::STATE_RUNNING, $store->readStatus()['state']);

        $store->appendLog("applying...\n");
        $store->appendLog("done\n");
        self::assertSame("applying...\ndone\n", $store->readLog());

        $store->finishMigration(true);
        $status = $store->readStatus();
        self::assertSame(InstallStateStore::STATE_OK, $status['state']);
        self::assertNotNull($status['finishedAt']);
        self::assertNull($status['error']);
    }

    public function testBeginMigrationTruncatesPreviousLog(): void
    {
        $store = $this->store();
        $store->appendLog('stale output');
        $store->beginMigration();
        self::assertSame('', $store->readLog());
    }

    public function testFailureRecordsError(): void
    {
        $store = $this->store();
        $store->beginMigration();
        $store->finishMigration(false, 'boom');

        $status = $store->readStatus();
        self::assertSame(InstallStateStore::STATE_FAILED, $status['state']);
        self::assertSame('boom', $status['error']);
    }

    /** The ready flag is keyed by version, so a newer shipped version invalidates the cached one. */
    public function testReadyFlagIsVersionKeyed(): void
    {
        $store = $this->store();

        self::assertFalse($store->isReadyFor('v1'));

        $store->markReady('v1');
        self::assertTrue($store->isReadyFor('v1'));
        self::assertFalse($store->isReadyFor('v2'));

        $store->clearReady();
        self::assertFalse($store->isReadyFor('v1'));
    }
}
