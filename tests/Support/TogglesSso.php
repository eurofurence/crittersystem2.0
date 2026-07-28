<?php

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Boots a client with SSO switched on, for the surfaces that only exist once an identity provider
 * is connected. SsoConfig reads `%env(bool:SSO_ENABLED)%` and the test environment ships it off, so
 * the variables have to change before the kernel boots.
 */
trait TogglesSso
{
    /** @var array<string, string|null> */
    private array $ssoEnvBackup = [];

    private function bootWithSso(): KernelBrowser
    {
        self::ensureKernelShutdown();

        // $_ENV wins over $_SERVER in Symfony's env resolution and .env already sets SSO_ENABLED=0,
        // so both have to be overridden. The originals are restored afterwards rather than unset:
        // deleting a variable .env defined leaves the next kernel unable to resolve it at all.
        foreach (['SSO_ENABLED' => '1', 'SSO_CLIENT_ID' => 'test-client'] as $name => $value) {
            $this->ssoEnvBackup[$name] ??= $_ENV[$name] ?? null;
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        return $this->client;
    }

    private function restoreSsoEnv(): void
    {
        foreach ($this->ssoEnvBackup as $name => $original) {
            if ($original === null) {
                unset($_ENV[$name], $_SERVER[$name]);
                continue;
            }

            $_ENV[$name] = $_SERVER[$name] = $original;
        }

        $this->ssoEnvBackup = [];
    }
}
