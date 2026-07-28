<?php

namespace App\Security;

use App\Service\EventConfigStore;
use App\Sso\SsoConfig;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Decides whether username+password sign-in is available, which an administrator may switch off
 * once an identity provider is carrying the sign-in flow.
 *
 * Two rules keep the switch from becoming a foot-gun:
 *
 *  - It is ignored while SSO is disabled. Otherwise turning it on would leave no way into the app.
 *  - Accounts holding ROLE_ADMIN keep password access. An identity provider that is unreachable,
 *    misconfigured or mid-migration would otherwise lock the operators out of their own
 *    installation, with no route left to switch the setting back.
 *
 * The switch withholds authentication itself, not merely the form: hiding the fields while the
 * authenticator still accepted a posted password would leave the brute-force surface fully open to
 * anything that skips the page.
 */
final class LocalLoginPolicy
{
    public function __construct(
        private readonly EventConfigStore $store,
        private readonly SsoConfig $sso,
    ) {
    }

    /** Whether the login page offers the username+password form at all. */
    public function isPasswordLoginOffered(): bool
    {
        if (!$this->sso->isEnabled()) {
            return true;
        }

        return $this->store->getBool(EventConfigStore::KEY_PASSWORD_LOGIN_ENABLED, true);
    }

    /** Whether this user may still authenticate with a password once the form is switched off. */
    public function permits(UserInterface $user): bool
    {
        return $this->isPasswordLoginOffered() || \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
