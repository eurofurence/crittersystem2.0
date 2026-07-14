<?php

namespace App\Service\Invite;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Department;
use App\Entity\RequestLink;
use App\Entity\User;
use App\Entity\UserGroupAssignment;
use App\Enum\RequestLinkType;
use App\Repository\GroupRepository;
use App\Repository\RequestLinkRepository;
use App\Repository\SsoGroupMappingRepository;
use App\Repository\UserGroupAssignmentRepository;
use App\Service\EventConfigStore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Department Availability Request and Shift Application Invitation links. Both
 * are department-bound, login-required, expirable and
 * revocable. Using a link may auto-add the user to a non-SSO department only
 * when the admin has enabled the global toggle; SSO-managed department
 * membership is never changed. Creation, revocation and use are audited.
 */
final class RequestLinkService
{
    /** Base membership group used for auto-join. */
    private const MEMBER_GROUP_SLUG = 'volunteer';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestLinkRepository $links,
        private readonly SsoGroupMappingRepository $ssoMappings,
        private readonly GroupRepository $groups,
        private readonly UserGroupAssignmentRepository $memberships,
        private readonly EventConfigStore $config,
        private readonly AuditLogger $audit,
    ) {
    }

    public function create(RequestLinkType $type, Department $department, ?User $creator, ?\DateTimeImmutable $expiresAt = null): RequestLink
    {
        $link = new RequestLink($type, $department, bin2hex(random_bytes(24)), $creator, $expiresAt);
        $this->em->persist($link);
        $this->em->flush();

        $this->audit->log(AuditEvents::CONFIGURATION, AuditEvents::INVITE_CREATE, [
            'resourceType' => 'request_link',
            'resourceId' => (string) $link->getId(),
            'details' => ['type' => $type->value, 'department' => $department->getName()],
        ]);

        return $link;
    }

    public function revoke(RequestLink $link): void
    {
        $link->revoke();
        $this->em->flush();
        $this->audit->log(AuditEvents::CONFIGURATION, AuditEvents::INVITE_REVOKE, [
            'resourceType' => 'request_link',
            'resourceId' => (string) $link->getId(),
        ]);
    }

    /** Reopen an availability request past its deadline. */
    public function reopen(RequestLink $link, ?\DateTimeImmutable $newDeadline): void
    {
        $link->setExpiresAt($newDeadline);
        $this->em->flush();
        $this->audit->log(AuditEvents::CONFIGURATION, AuditEvents::INVITE_REOPEN, [
            'resourceType' => 'request_link',
            'resourceId' => (string) $link->getId(),
        ]);
    }

    public function findActiveByToken(string $token): ?RequestLink
    {
        $link = $this->links->findOneByToken($token);

        return $link !== null && $link->isActive() ? $link : null;
    }

    public function isSsoManaged(Department $department): bool
    {
        return $this->ssoMappings->existsForDepartment($department);
    }

    public function autoMembershipEnabled(): bool
    {
        return $this->config->getBool(EventConfigStore::KEY_MEMBERSHIP_AUTO_FROM_LINKS, false);
    }

    /**
     * Record a link use and, when permitted, auto-add the user to the department.
     * Auto-join happens only for a non-SSO department with the
     * global toggle enabled; SSO membership is never touched. Returns true when
     * the user was newly added.
     */
    public function use(RequestLink $link, User $user): bool
    {
        $department = $link->getDepartment();
        $this->audit->log(AuditEvents::CONFIGURATION, AuditEvents::INVITE_USE, [
            'resourceType' => 'request_link',
            'resourceId' => (string) $link->getId(),
            'resourceOwnerId' => $user->getId(),
        ]);

        if ($this->isSsoManaged($department) || !$this->autoMembershipEnabled()) {
            return false;
        }
        if ($this->memberships->userIsMember($user, $department)) {
            return false;
        }

        $group = $this->groups->findOneBySlug(self::MEMBER_GROUP_SLUG);
        if ($group === null) {
            return false;
        }

        $this->em->persist(new UserGroupAssignment($user, $group, $department));
        $this->em->flush();
        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::AUTO_MEMBERSHIP, [
            'resourceType' => 'department',
            'resourceId' => (string) $department->getId(),
            'resourceOwnerId' => $user->getId(),
        ]);

        return true;
    }
}
