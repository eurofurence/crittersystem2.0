<?php

namespace App\Service\Shift;

use App\Entity\Department;
use App\Entity\Shift;
use App\Entity\ShiftEntry;
use App\Entity\User;
use App\Security\PrivilegeCatalog;
use App\Service\Chat\ConversationService;
use App\Service\DepartmentContactResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Everything known about one shift, filtered for one viewer.
 *
 * The dossier exists because a volunteer arriving at the info desk with a shift they do not
 * understand needs the operator to see who planned it, who else is on it and what it actually says.
 * Two tiers, and this class is the only place that decides which one applies - a template that
 * decided for itself would be a second answer to the same question.
 *
 * The privileged tier is checked **against the shift's own department**. Without a subject
 * {@see \App\Security\PrivilegeVoter} grants unconditionally, so an unscoped check here would hand
 * every department's roster to anybody who may reach a shift page at all. Passing the department is
 * also what makes the event-wide case work: a grant carrying no department scope resolves to every
 * department, so an info-desk operator still sees the whole event.
 *
 * No personal data crosses this boundary at either tier. Everybody named is named by username,
 * which is not PII, and contact runs through the existing conversation service rather than by
 * handing out an address.
 */
final class ShiftDossierPresenter
{
    /**
     * Any one of these makes a viewer answerable for the shift in some way, and each already implies
     * access to at least part of what the privileged tier shows through an existing screen.
     */
    private const PRIVILEGED = ['shift:manage', 'assignment:manage', 'shift:assign'];

    public function __construct(
        private readonly Security $security,
        private readonly ShiftEligibility $eligibility,
        private readonly ShiftGroupResolver $groups,
        private readonly DepartmentContactResolver $contacts,
        private readonly ConversationService $conversations,
    ) {
    }

    /**
     * @return array{
     *     shift: Shift,
     *     privileged: bool,
     *     staffing: list<array{type: \App\Entity\VolunteerType, needed: int, assigned: int}>,
     *     siblings: Shift[],
     *     roster: ShiftEntry[],
     *     managers: User[],
     *     canMessage: bool,
     *     descriptionMissing: bool,
     *     canSeeStaffing: bool,
     *     canEditShift: bool,
     *     canViewDepartment: bool
     * }
     */
    public function present(Shift $shift, User $viewer): array
    {
        $department = $shift->getDepartment();
        $privileged = $this->isPrivileged($shift, $viewer);

        return [
            'shift' => $shift,
            'privileged' => $privileged,
            'staffing' => $this->eligibility->availability($shift),
            'siblings' => $this->groups->isFullyVisibleTo($shift, $viewer) ? $this->groups->siblingsOf($shift) : [],
            'roster' => $privileged ? $this->roster($shift) : [],
            'managers' => $privileged && $department !== null ? $this->contacts->managersOf($department) : [],
            'canMessage' => $privileged && $this->conversations->enabled() && $this->conversations->canInitiateDirect($viewer),
            'descriptionMissing' => $shift->getDescription() === null,
            'canSeeStaffing' => $department !== null && $this->security->isGranted('assignment:manage', $department),
            'canEditShift' => $department !== null && $this->security->isGranted('shift:manage', $department),
            'canViewDepartment' => $department !== null && $this->canReachDepartmentPage($department),
        ];
    }

    /**
     * The department page enforces ROLE_STAFF, and admin-only for an organizational department.
     * The link mirrors both, so it is never offered to somebody it would answer with a 403.
     */
    private function canReachDepartmentPage(Department $department): bool
    {
        if (!$this->security->isGranted('ROLE_STAFF')) {
            return false;
        }

        return !$department->isOrganizational() || $this->security->isGranted(PrivilegeCatalog::SUPER);
    }

    /**
     * Whether the viewer may see who planned the shift and who is on it.
     *
     * A shift whose department has somehow been lost cannot be scoped, and an unscoped check would
     * grant. It is refused instead: the shift is broken, not public.
     */
    public function isPrivileged(Shift $shift, User $viewer): bool
    {
        $department = $shift->getDepartment();
        if ($department === null) {
            return false;
        }

        foreach (self::PRIVILEGED as $privilege) {
            if ($this->security->isGranted($privilege, $department)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The roster, assigned before applied and then by username, so the people committed to the shift
     * read first.
     *
     * @return ShiftEntry[]
     */
    private function roster(Shift $shift): array
    {
        $entries = $shift->getEntries()->toArray();
        usort($entries, static function (ShiftEntry $a, ShiftEntry $b): int {
            return [$a->isApplication(), mb_strtolower($a->getUser()->getName())]
                <=> [$b->isApplication(), mb_strtolower($b->getUser()->getName())];
        });

        return $entries;
    }
}
