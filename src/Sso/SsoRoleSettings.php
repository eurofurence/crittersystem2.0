<?php

declare(strict_types=1);

namespace App\Sso;

use App\Service\EventConfigStore;

/**
 * The identity-provider role IDs the app reacts to: the two that decide a user's position in every
 * department they are mapped into ({@see SsoDepartmentPositions}), and the two that grant global
 * admin / sub admin ({@see SsoGlobalRoles}). An unset role never matches, so leaving one blank
 * simply disables that grant.
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

    public function globalAdminRole(): ?string
    {
        return $this->read(EventConfigStore::KEY_SSO_ROLE_GLOBAL_ADMIN);
    }

    public function subAdminRole(): ?string
    {
        return $this->read(EventConfigStore::KEY_SSO_ROLE_SUB_ADMIN);
    }

    public function save(?string $departmentManagerRole, ?string $shiftManagerRole, ?string $globalAdminRole, ?string $subAdminRole): void
    {
        $this->config->set(EventConfigStore::KEY_SSO_ROLE_DEPARTMENT_MANAGER, trim((string) $departmentManagerRole));
        $this->config->set(EventConfigStore::KEY_SSO_ROLE_SHIFT_MANAGER, trim((string) $shiftManagerRole));
        $this->config->set(EventConfigStore::KEY_SSO_ROLE_GLOBAL_ADMIN, trim((string) $globalAdminRole));
        $this->config->set(EventConfigStore::KEY_SSO_ROLE_SUB_ADMIN, trim((string) $subAdminRole));
        $this->config->flush();
    }

    private function read(string $key): ?string
    {
        $value = trim($this->config->getString($key, ''));

        return $value === '' ? null : $value;
    }
}
