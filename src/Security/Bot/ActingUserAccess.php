<?php

namespace App\Security\Bot;

use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Authorization for /api/bot, decided against the acting volunteer.
 *
 * The firewall token identifies the bot, not a person, so `#[IsGranted]` and
 * `$this->denyAccessUnlessGranted()` would check the wrong identity entirely.
 * This runs the same voters against the acting user instead.
 *
 * Always pass $subject for a department-scoped privilege (PrivilegeCatalog::SCOPED:
 * department:manage, shift:manage, shift:assign, assignment:manage,
 * volunteertype:assign). PrivilegeVoter only enforces the department scope when a
 * subject is supplied - without one, a manager scoped to a single department is
 * granted across the whole event.
 */
final class ActingUserAccess
{
    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    public function isGranted(User $actor, string $privilege, mixed $subject = null): bool
    {
        $token = new UsernamePasswordToken($actor, 'api_bot', $actor->getRoles());

        return $this->accessDecisionManager->decide($token, [$privilege], $subject);
    }

    public function denyUnlessGranted(User $actor, string $privilege, mixed $subject = null): void
    {
        if (!$this->isGranted($actor, $privilege, $subject)) {
            throw new AccessDeniedHttpException(sprintf('Missing privilege "%s".', $privilege));
        }
    }
}
