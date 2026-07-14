<?php

namespace App\Service;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Department;
use App\Entity\DelegatedManagerRequest;
use App\Entity\User;
use App\Entity\UserGroupAssignment;
use App\Enum\DepartmentPosition;
use App\Notification\NotificationCategories;
use App\Repository\DelegatedManagerRequestRepository;
use App\Repository\GroupRepository;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Delegated Shift Manager approval workflow. Approving a request grants a department-scoped,
 * time-boxed group assignment that expires at the end of the event.
 *
 * Placing members and changing their position is {@see DepartmentMemberService}; those grants are
 * permanent and are not part of this workflow.
 */
class DelegatedManagerService
{
    private const DELEGATED_SLUG = DepartmentPosition::DELEGATED_SHIFT_MANAGER_SLUG;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DelegatedManagerRequestRepository $requests,
        private readonly GroupRepository $groups,
        private readonly EventConfigStore $config,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    public function request(Department $department, User $subject, ?User $requestedBy): DelegatedManagerRequest
    {
        $existing = $this->requests->findPending($department, $subject);
        if ($existing !== null) {
            return $existing;
        }

        $request = new DelegatedManagerRequest($department, $subject, $requestedBy);
        $this->em->persist($request);
        $this->em->flush();

        $this->audit->log(AuditEvents::ACCESS_CONTROL, AuditEvents::CREATE, [
            'resourceType' => 'DelegatedManagerRequest',
            'resourceId' => $request->getId(),
            'details' => ['department' => $department->getId(), 'subject' => $subject->getId()],
        ]);

        return $request;
    }

    public function approve(DelegatedManagerRequest $request, User $approver): void
    {
        if (!$request->isPending()) {
            return;
        }

        $request->decide(DelegatedManagerRequest::STATUS_APPROVED, $approver);
        $this->grant($request->getSubject(), $request->getDepartment(), self::DELEGATED_SLUG);
        $this->em->flush();

        $this->audit->log(AuditEvents::ACCESS_CONTROL, AuditEvents::GRANT, [
            'resourceType' => 'DelegatedManagerRequest',
            'resourceId' => $request->getId(),
            'details' => ['approved' => true],
        ]);
        $this->notifications->notify(
            $request->getSubject(),
            NotificationCategories::GENERAL,
            'Delegated shift manager approved',
            sprintf('You are now a delegated shift manager for %s.', $request->getDepartment()->getName()),
        );
    }

    public function reject(DelegatedManagerRequest $request, User $approver): void
    {
        if (!$request->isPending()) {
            return;
        }

        $request->decide(DelegatedManagerRequest::STATUS_REJECTED, $approver);
        $this->em->flush();

        $this->audit->log(AuditEvents::ACCESS_CONTROL, AuditEvents::REVOKE, [
            'resourceType' => 'DelegatedManagerRequest',
            'resourceId' => $request->getId(),
            'details' => ['approved' => false],
        ]);
        $this->notifications->notify(
            $request->getSubject(),
            NotificationCategories::GENERAL,
            'Delegated shift manager declined',
            sprintf('Your delegated shift manager request for %s was declined.', $request->getDepartment()->getName()),
        );
    }

    private function grant(User $subject, Department $department, string $groupSlug): void
    {
        $group = $this->groups->findOneBySlug($groupSlug);
        if ($group === null) {
            return;
        }

        // Time-box delegated grants to the end of teardown when configured.
        $expiresAt = $this->config->getDate(EventConfigStore::KEY_TEARDOWN_END);

        $this->em->persist(new UserGroupAssignment($subject, $group, $department, $expiresAt));
    }
}
