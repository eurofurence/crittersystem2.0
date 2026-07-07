<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserProfileTest extends TestCase
{
    public function testManualAccountMayEditFullNameOnly(): void
    {
        $user = new User();
        self::assertSame('manual', $user->getAccountSource());
        self::assertFalse($user->isSsoManaged());
        self::assertTrue($user->canEditFullName());
        self::assertFalse($user->canEditUsername());
        self::assertFalse($user->canEditEmail());
    }

    public function testSsoAccountMayNotEditAnyIdentity(): void
    {
        $user = new User();
        $user->setAccountSource(User::SOURCE_SSO)->setSsoUserId('abc')->setSsoProvider('keycloak');

        self::assertTrue($user->isSsoManaged());
        self::assertFalse($user->canEditFullName());
        self::assertFalse($user->canEditUsername());
        self::assertFalse($user->canEditEmail());
        self::assertSame('abc', $user->getSsoUserId());
    }

    public function testOnboardingLifecycle(): void
    {
        $user = new User();
        self::assertFalse($user->isOnboardingCompleted());

        $user->completeOnboarding();
        self::assertTrue($user->isOnboardingCompleted());
        self::assertNotNull($user->getOnboardingCompletedAt());

        $user->resetOnboarding();
        self::assertFalse($user->isOnboardingCompleted());
        self::assertNull($user->getOnboardingCompletedAt());
    }
}
