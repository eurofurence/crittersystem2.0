<?php

namespace App\Tests\Unit\Service;

use App\Service\PiiMasker;
use App\TwoFactor\StepUpManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PiiMaskerTest extends TestCase
{
    private function masker(bool $canSee, bool $stepUpFresh = true): PiiMasker
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($canSee);

        // StepUpManager is final; drive it through a real session instead of a double.
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        if ($stepUpFresh) {
            $session->set('_mfa_verified_at', time());
        }
        $stack = new RequestStack();
        $stack->push($request);

        return new PiiMasker($security, new StepUpManager($stack));
    }

    public function testAdminWithFreshStepUpSeesRawEmail(): void
    {
        self::assertSame('john.doe@example.com', $this->masker(true)->email('john.doe@example.com'));
    }

    public function testPrivilegeWithoutFreshStepUpStillMasked(): void
    {
        $masked = $this->masker(true, false)->email('john.doe@example.com');
        self::assertNotSame('john.doe@example.com', $masked);
        self::assertStringContainsString('•', $masked);
        self::assertFalse($this->masker(true, false)->canSeePii());
        self::assertTrue($this->masker(true, false)->canRevealPii());
    }

    public function testNonAdminGetsMaskedEmail(): void
    {
        $masked = $this->masker(false)->email('john.doe@example.com');
        self::assertNotSame('john.doe@example.com', $masked);
        self::assertStringContainsString('@', $masked);
        self::assertStringContainsString('•', $masked);
        // No privilege means no reveal control, only masking.
        self::assertFalse($this->masker(false)->canRevealPii());
    }

    public function testGenericMaskingRespectsPermission(): void
    {
        self::assertSame('12345', $this->masker(true)->generic('12345'));
        self::assertSame('••••••', $this->masker(false)->generic('12345'));
        self::assertSame('••••••', $this->masker(true, false)->generic('12345'));
        self::assertSame('', $this->masker(false)->generic(null));
    }
}
