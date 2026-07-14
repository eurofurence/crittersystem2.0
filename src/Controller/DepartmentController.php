<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\User;
use App\Enum\DepartmentPosition;
use App\Repository\DelegatedManagerRequestRepository;
use App\Repository\UserRepository;
use App\Service\ContactMethodResolver;
use App\Service\DepartmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff-facing departments overview: cards for every visible
 * department and a detail dashboard with membership tables and a staffing widget.
 */
#[IsGranted('ROLE_STAFF')]
final class DepartmentController extends AbstractController
{
    public function __construct(
        private readonly DepartmentService $departments,
        private readonly DelegatedManagerRequestRepository $delegatedRequests,
        private readonly ContactMethodResolver $contacts,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Users who can still be placed in the department: everyone who is not already a member, minus
     * SSO-managed accounts, whose membership the identity provider owns.
     *
     * @param array{managers: User[], shiftManagers: User[], staff: User[], nonStaff: User[]} $members
     *
     * @return User[]
     */
    private function assignableUsers(array $members): array
    {
        $seen = [];
        foreach (array_merge($members['managers'], $members['shiftManagers'], $members['staff'], $members['nonStaff']) as $member) {
            $seen[$member->getId()] = true;
        }

        return array_values(array_filter(
            $this->users->findBy([], ['name' => 'ASC']),
            static fn (User $user): bool => !isset($seen[$user->getId()]) && !$user->isSsoManaged(),
        ));
    }

    #[Route('/departments', name: 'app_departments', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('department/index.html.twig', [
            'rows' => $this->departments->visibleDepartments($this->getUser()),
        ]);
    }

    #[Route('/departments/{id}', name: 'app_department_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity(mapping: ['id' => 'uuid'])] Department $department): Response
    {
        // Organizational departments are admin-only.
        if ($department->isOrganizational() && !$this->isGranted('global:admin')) {
            throw $this->createAccessDeniedException();
        }

        $members = $this->departments->members($department);
        $stats = [];
        $contactMethods = [];
        foreach (array_merge($members['managers'], $members['shiftManagers'], $members['staff'], $members['nonStaff']) as $user) {
            $stats[$user->getId()] = [
                'hours' => $this->departments->plannedHours($user),
                'over' => $this->departments->overThreshold($user),
            ];
            $contactMethods[$user->getId()] = $this->contacts->methodsFor($user);
        }

        return $this->render('department/show.html.twig', [
            'department' => $department,
            'members' => $members,
            'staffing' => $this->departments->staffing($department),
            'stats' => $stats,
            'contactMethods' => $contactMethods,
            'pending' => $this->delegatedRequests->findPendingByDepartment($department),
            'positions' => DepartmentPosition::cases(),
            'assignable' => $this->isGranted('department:member:manage')
                ? $this->assignableUsers($members)
                : [],
        ]);
    }
}
