<?php

namespace App\Tests\Unit\Sso;

use App\Sso\SsoConfig;
use PHPUnit\Framework\TestCase;

final class SsoConfigTest extends TestCase
{
    private function config(bool $enabled, string $clientId = 'client-abcdef'): SsoConfig
    {
        return new SsoConfig($enabled, 'https://idp/.well-known/openid-configuration', $clientId, 'secret', '', '', '', 'openid profile email', 'keycloak');
    }

    public function testEnabledRequiresFlagAndClientId(): void
    {
        self::assertTrue($this->config(true)->isEnabled());
        self::assertFalse($this->config(false)->isEnabled());
        self::assertFalse($this->config(true, '')->isEnabled());
    }

    public function testScopesAreSplit(): void
    {
        self::assertSame(['openid', 'profile', 'email'], $this->config(true)->scopes());
    }

    public function testClientIdIsTruncatedForDisplay(): void
    {
        $masked = $this->config(true, 'client-abcdef')->truncatedClientId();
        self::assertStringStartsWith('cli', $masked);
        self::assertStringContainsString('•', $masked);
        self::assertStringNotContainsString('abcde', $masked);
    }

    public function testUsesDiscovery(): void
    {
        self::assertTrue($this->config(true)->usesDiscovery());
    }
}
