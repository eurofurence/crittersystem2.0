<?php

declare(strict_types=1);

namespace App\Audit;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records auditable actions. Captures the actor and request context now, then
 * dispatches an {@see AuditRecord} on the bus so the database write happens off
 * the request path (async transport) and never blocks the user.
 *
 * Use {@see log()} for an authenticated action, {@see system()} for actions the
 * application takes on its own (jobs, automatic cleanups), and pass an explicit
 * username via $options for events with no established session (failed logins).
 */
final class AuditLogger
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $options eventType-agnostic overrides:
     *   outcome, resourceType, resourceId, resourceOwnerId, resource[],
     *   details[], httpStatus, errorMessage, actorUsername (when no session)
     */
    public function log(string $eventType, string $action, array $options = []): void
    {
        $this->bus->dispatch($this->build($eventType, $action, $options, false));
    }

    /** Log an action taken by the system itself rather than a user. */
    public function system(string $eventType, string $action, array $options = []): void
    {
        $this->bus->dispatch($this->build($eventType, $action, $options, true));
    }

    /**
     * An explicit actor (during login, for instance, before the token is in context) takes
     * precedence over the ambient security user.
     *
     * @param array<string, mixed> $options
     */
    private function build(string $eventType, string $action, array $options, bool $system): AuditRecord
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $options['actorUser'] ?? ($system ? null : $this->security->getUser());
        $token = $this->security->getToken();

        $actorType = 'system';
        $actorUserId = null;
        $actorUsername = $options['actorUsername'] ?? null;
        $actorRole = null;
        $actorSsoId = null;

        if ($user instanceof User) {
            $actorType = 'user';
            $actorUserId = $user->getId();
            $actorUsername = $user->getUserIdentifier();
            $actorRole = $this->highestRole($user->getRoles());
            $actorSsoId = method_exists($user, 'getSsoUserId') ? $user->getSsoUserId() : null;
        }

        return new AuditRecord(
            eventId: 'evt_'.bin2hex(random_bytes(8)),
            occurredAt: new \DateTimeImmutable(),
            eventType: $eventType,
            action: $action,
            outcome: (string) ($options['outcome'] ?? AuditEvents::SUCCESS),
            actorType: $actorType,
            actorUserId: $actorUserId,
            actorSsoId: $actorSsoId,
            actorUsername: self::fit($actorUsername, 128),
            actorRole: $actorRole,
            actorIp: $request?->getClientIp(),
            actorUserAgent: $request?->headers->get('User-Agent'),
            resourceType: self::fit($options['resourceType'] ?? null, 64),
            resourceId: self::fit(isset($options['resourceId']) ? (string) $options['resourceId'] : null, 128),
            resourceOwnerId: self::fit(isset($options['resourceOwnerId']) ? (string) $options['resourceOwnerId'] : null, 128),
            resource: $options['resource'] ?? [],
            details: $options['details'] ?? [],
            sessionId: $this->sessionId($request),
            requestUrl: $request?->getUri(),
            mfaVerified: $token !== null && $token->hasAttribute('mfa_verified') && $token->getAttribute('mfa_verified') === true,
            httpStatus: isset($options['httpStatus']) ? (int) $options['httpStatus'] : null,
            errorMessage: $options['errorMessage'] ?? null,
        );
    }

    /**
     * Caps a value at its column width.
     *
     * Some of these fields carry unvalidated caller input - actorUsername is whatever identifier was
     * typed into the login form, and a resourceId can be a user-supplied key. Overflowing the column
     * throws while the audit record is being handled, which takes down the request that was being
     * audited. An over-long value is never a real identifier, so keeping the record beats losing
     * both it and the response. DO NOT CHANGE THIS
     */
    private static function fit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function sessionId(?\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        if ($request === null || !$request->hasSession()) {
            return null;
        }
        $session = $request->getSession();

        return $session->isStarted() ? $session->getId() : null;
    }

    /** @param string[] $roles */
    private function highestRole(array $roles): ?string
    {
        foreach (['ROLE_ADMIN', 'ROLE_SUBADMIN', 'ROLE_STAFF', 'ROLE_USER'] as $role) {
            if (\in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }
}
