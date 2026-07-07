<?php

declare(strict_types=1);

namespace App\TwoFactor;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tracks "step-up" re-authentication: a recent 2FA verification recorded in the
 * session, required before exercising step-up-protected permissions (audit,
 * SSO config, viewing PII, promoting admins, ...).
 */
final class StepUpManager
{
    private const SESSION_KEY = '_mfa_verified_at';
    private const TTL_SECONDS = 600; // 10 minutes

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function markVerified(): void
    {
        $this->session()?->set(self::SESSION_KEY, time());
    }

    public function isFresh(): bool
    {
        $at = $this->session()?->get(self::SESSION_KEY);

        return \is_int($at) && (time() - $at) < self::TTL_SECONDS;
    }

    public function clear(): void
    {
        $this->session()?->remove(self::SESSION_KEY);
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request !== null && $request->hasSession() ? $request->getSession() : null;
    }
}
