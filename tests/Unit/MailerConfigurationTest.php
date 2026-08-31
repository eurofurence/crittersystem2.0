<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MailerConfigurationTest extends TestCase
{
    public function testMailerFromIsConfiguredGloballyWithBackwardCompatibleDefault(): void
    {
        $config = file_get_contents(__DIR__.'/../../config/packages/mailer.yaml');

        self::assertIsString($config);
        self::assertStringContainsString("env(MAILER_FROM): 'noreply@critter.example'", $config);
        self::assertStringContainsString("From: '%env(MAILER_FROM)%'", $config);
    }

    public function testApplicationCodeDoesNotHardCodeTheDefaultSender(): void
    {
        $sourceDir = realpath(__DIR__.'/../../src');
        self::assertNotFalse($sourceDir);

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            self::assertStringNotContainsString(
                'noreply@critter.example',
                $contents,
                $file->getPathname().' still hard-codes the default mail sender.',
            );
        }
    }
}
