<?php

namespace App\Tests\Unit\Storage;

use App\Storage\FileStorage;
use App\Storage\FileStorageFactory;
use PHPUnit\Framework\TestCase;

final class FileStorageTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/critter-storage-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tmpDir);
        }
    }

    private function storage(): FileStorage
    {
        // projectDir is irrelevant here because the DSN path is absolute.
        $filesystem = (new FileStorageFactory('/nonexistent'))->create('local://' . $this->tmpDir);

        return new FileStorage($filesystem);
    }

    public function testLocalDriverRoundTrips(): void
    {
        $storage = $this->storage();

        self::assertFalse($storage->exists('chat/hello.txt'));

        $storage->write('chat/hello.txt', 'hello world', 'text/plain');

        self::assertTrue($storage->exists('chat/hello.txt'));
        self::assertSame('hello world', $storage->read('chat/hello.txt'));

        $storage->delete('chat/hello.txt');
        self::assertFalse($storage->exists('chat/hello.txt'));
    }

    public function testRelativeLocalPathResolvesUnderProjectDir(): void
    {
        $factory = new FileStorageFactory($this->tmpDir);
        $filesystem = $factory->create('local://var/uploads');
        $storage = new FileStorage($filesystem);

        $storage->write('avatars/a.txt', 'x');

        self::assertFileExists($this->tmpDir . '/var/uploads/avatars/a.txt');
    }

    public function testUnknownSchemeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FileStorageFactory('/tmp'))->create('ftp://example.com/x');
    }

    public function testS3DsnBuildsAFilesystemWithoutContacting(): void
    {
        // Constructing the S3-backed filesystem must not require network I/O.
        $filesystem = (new FileStorageFactory('/tmp'))->create('s3://my-bucket?region=eu-central-1&prefix=uploads');

        self::assertInstanceOf(\League\Flysystem\FilesystemOperator::class, $filesystem);
    }
}
