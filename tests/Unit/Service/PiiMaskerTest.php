<?php

namespace App\Tests\Unit\Service;

use App\Service\PiiMasker;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class PiiMaskerTest extends TestCase
{
    private function masker(bool $canSee): PiiMasker
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($canSee);

        return new PiiMasker($security);
    }

    public function testAdminSeesRawEmail(): void
    {
        self::assertSame('john.doe@example.com', $this->masker(true)->email('john.doe@example.com'));
    }

    public function testNonAdminGetsMaskedEmail(): void
    {
        $masked = $this->masker(false)->email('john.doe@example.com');
        self::assertNotSame('john.doe@example.com', $masked);
        self::assertStringContainsString('@', $masked);
        self::assertStringContainsString('•', $masked);
    }

    public function testGenericMaskingRespectsPermission(): void
    {
        self::assertSame('12345', $this->masker(true)->generic('12345'));
        self::assertSame('••••••', $this->masker(false)->generic('12345'));
        self::assertSame('', $this->masker(false)->generic(null));
    }
}
