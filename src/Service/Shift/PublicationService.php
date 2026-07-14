<?php

namespace App\Service\Shift;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\User;
use App\Enum\ShiftState;
use App\Notification\NotificationCategories;
use App\Repository\ShiftRepository;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Publishes a department's draft shifts. Publication validates
 * required fields, rejects stale writes, flips the drafts to published
 * atomically, audits each PUBLISH, and only then triggers notifications to
 * anyone already assigned — so a failed publish never leaks notifications.
 */
final class PublicationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShiftRepository $shifts,
        private readonly ShiftConcurrency $concurrency,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Publish every draft shift in the department.
     *
     * @param array<int, int> $expectedVersions shiftId => version the client last
     *                                           saw; a mismatch aborts the whole
     *                                           publish
     *
     * @throws \App\Exception\StaleWriteException when a draft changed underneath
     */
    public function publishDepartmentDrafts(Department $department, array $expectedVersions = [], ?User $actor = null): PublicationResult
    {
        /** @var Shift[] $drafts */
        $drafts = $this->shifts->findBy(['department' => $department, 'state' => ShiftState::DRAFT->value]);

        if ($drafts === []) {
            return new PublicationResult([], [], ['There are no draft shifts to publish.']);
        }

        // Validate first; a hard error blocks the entire publish (atomic).
        $errors = [];
        $warnings = [];
        foreach ($drafts as $draft) {
            foreach ($this->validate($draft) as $error) {
                $errors[] = $error;
            }
            if ($draft->getShiftTask() === null) {
                $warnings[] = \sprintf('"%s" has no Shift Task set.', $draft->getTitle());
            }
        }
        if ($errors !== []) {
            return new PublicationResult([], $warnings, $errors);
        }

        // Reject a stale publish before mutating anything.
        foreach ($drafts as $draft) {
            if (isset($expectedVersions[$draft->getId()])) {
                $this->concurrency->assertVersion($draft, $expectedVersions[$draft->getId()]);
            }
        }

        $published = $this->concurrency->transactional(function () use ($drafts, $actor): array {
            foreach ($drafts as $draft) {
                $draft->setState(ShiftState::PUBLISHED);
                if ($actor !== null) {
                    $draft->setUpdatedBy($actor);
                }
            }
            $this->em->flush();

            foreach ($drafts as $draft) {
                $this->audit->log(AuditEvents::SHIFT, AuditEvents::PUBLISH, [
                    'resourceType' => 'shift',
                    'resourceId' => (string) $draft->getId(),
                    'details' => ['title' => $draft->getTitle()],
                ]);
            }

            return $drafts;
        });

        // Notifications happen only after a successful commit.
        $this->notifyAssignees($published);

        return new PublicationResult($published, $warnings, []);
    }

    /** @return list<string> hard validation errors for a single draft */
    private function validate(Shift $shift): array
    {
        $errors = [];
        if (trim($shift->getTitle()) === '') {
            $errors[] = 'A shift is missing a title.';
        }
        if ($shift->getDepartment() === null) {
            $errors[] = \sprintf('"%s" has no owning department.', $shift->getTitle());
        }
        if ($shift->getEndsAt() <= $shift->getStartsAt()) {
            $errors[] = \sprintf('"%s" must end after it starts.', $shift->getTitle());
        }

        return $errors;
    }

    /** @param Shift[] $shifts */
    private function notifyAssignees(array $shifts): void
    {
        foreach ($shifts as $shift) {
            foreach ($shift->getEntries() as $entry) {
                $this->notifications->notify(
                    $entry->getUser(),
                    NotificationCategories::SHIFT_ASSIGNMENT,
                    'Shift published',
                    \sprintf('Your shift "%s" has been published.', $shift->getTitle()),
                );
            }
        }
    }
}
