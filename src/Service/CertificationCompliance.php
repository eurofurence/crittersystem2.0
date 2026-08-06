<?php

namespace App\Service;

use App\Entity\Certification;
use App\Entity\User;
use App\Entity\VolunteerType;
use App\Repository\UserCertificationRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Repository\VolunteerTypeRepository;

/**
 * Who is missing a certification the role they hold requires.
 *
 * This is the instrument for deciding whether the requirement can be enforced on sign-up at all:
 * it says how many people are already in a position the rule would refuse. Run it, work through the
 * gaps, and only then turn enforcement on - switching it on blind leaves volunteers holding shifts
 * they can no longer be assigned to, with nothing on screen explaining why.
 *
 * Three queries regardless of how many roles, members or certifications there are: one for the
 * roles, one for their memberships, one for every relevant certification record. The obvious shape
 * - ask per member whether they hold each certification - is a query per person per requirement.
 */
final class CertificationCompliance
{
    public function __construct(
        private readonly VolunteerTypeRepository $types,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly UserCertificationRepository $records,
    ) {
    }

    /**
     * One entry per volunteer type that requires anything, with the members who fall short.
     *
     * @return list<array{
     *     type: VolunteerType,
     *     required: list<Certification>,
     *     members: int,
     *     compliant: int,
     *     gaps: list<array{user: User, missing: list<Certification>}>
     * }>
     */
    public function report(): array
    {
        $requiring = [];
        foreach ($this->types->findAllOrderedWithCertifications() as $type) {
            if (!$type->getCertifications()->isEmpty()) {
                $requiring[] = $type;
            }
        }
        if ($requiring === []) {
            return [];
        }

        $held = $this->heldByUser($requiring);
        // Only confirmed memberships: an unconfirmed request is not membership, so the person
        // cannot take shifts as that role yet and is not out of compliance with it either.
        $membersByType = $this->memberships->findConfirmedByTypes($requiring);

        $report = [];
        foreach ($requiring as $type) {
            $required = array_values($type->getCertifications()->toArray());
            $gaps = [];
            $members = 0;

            foreach ($membersByType[$type->getId()] ?? [] as $membership) {
                ++$members;

                $missing = [];
                foreach ($required as $certification) {
                    if (!isset($held[$membership->getUser()->getId()][$certification->getId()])) {
                        $missing[] = $certification;
                    }
                }
                if ($missing !== []) {
                    $gaps[] = ['user' => $membership->getUser(), 'missing' => $missing];
                }
            }

            $report[] = [
                'type' => $type,
                'required' => $required,
                'members' => $members,
                'compliant' => $members - \count($gaps),
                'gaps' => $gaps,
            ];
        }

        return $report;
    }

    /** @return array{types: int, members: int, gaps: int} totals across the whole report */
    public function totals(array $report): array
    {
        $members = 0;
        $gaps = 0;
        foreach ($report as $row) {
            $members += $row['members'];
            $gaps += \count($row['gaps']);
        }

        return ['types' => \count($report), 'members' => $members, 'gaps' => $gaps];
    }

    /**
     * Valid certifications indexed by user and certification id. Only records that count today are
     * kept: an expired or revoked one is exactly the gap this report exists to show.
     *
     * @param list<VolunteerType> $types
     *
     * @return array<int, array<int, true>>
     */
    private function heldByUser(array $types): array
    {
        $required = [];
        foreach ($types as $type) {
            foreach ($type->getCertifications() as $certification) {
                $required[$certification->getId()] = $certification;
            }
        }

        $held = [];
        foreach ($this->records->findForCertifications(array_values($required)) as $record) {
            if ($record->isValid()) {
                $held[$record->getUser()->getId()][$record->getCertification()->getId()] = true;
            }
        }

        return $held;
    }

    /**
     * The report as rows for a spreadsheet: one line per person who is short of something, because
     * that is the list somebody works through.
     *
     * @param list<array{type: VolunteerType, required: list<Certification>, members: int, compliant: int, gaps: list<array{user: User, missing: list<Certification>}>}> $report
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function rows(array $report): array
    {
        $rows = [];
        foreach ($report as $entry) {
            foreach ($entry['gaps'] as $gap) {
                $rows[] = [
                    $entry['type']->getName(),
                    $gap['user']->getName(),
                    implode(', ', array_map(static fn (Certification $c): string => $c->getTitle(), $gap['missing'])),
                ];
            }
        }

        return $rows;
    }
}
