<?php

declare(strict_types=1);

namespace App\Sso;

use App\Service\EventConfigStore;

/**
 * The two global identity-provider role IDs that decide a user's position in every department they
 * are mapped into. An unset role never matches, so leaving one blank simply disables that position.
 */
final class SsoRoleSettings
{
    public function __construct(private readonly EventConfigStore $config)
    {
    }

    public function departmentManagerRole(): ?string
    {
        return $this->read(EventConfigStore::KEY_SSO_ROLE_DEPARTMENT_MANAGER);
    }

    public function shiftManagerRole(): ?string
    {
        return $this->read(EventConfigStore::KEY_SSO_ROLE_SHIFT_MANAGER);
    }

    public function save(?string $departmentManagerRole, ?string $shiftManagerRole): void
    {
        $this->config->set(EventConfigStore::KEY_SSO_ROLE_DEPARTMENT_MANAGER, trim((string) $departmentManagerRole));
        $this->config->set(EventConfigStore::KEY_SSO_ROLE_SHIFT_MANAGER, trim((string) $shiftManagerRole));
        $this->config->flush();
    }

    private function read(string $key): ?string
    {
        $value = trim($this->config->getString($key, ''));

        return $value === '' ? null : $value;
    }
}
