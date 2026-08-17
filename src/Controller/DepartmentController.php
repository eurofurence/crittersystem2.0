<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\User;
use App\Enum\DepartmentPosition;
use App\Repository\DelegatedManagerRequestRepository;
use App\Repository\UserRepository;
use App\Service\ContactMethodResolver;
use App\Service\DepartmentService;
use App\Service\UserSearchResultFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    #[Route('/departments', name: 'app_departments', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('department/index.html.twig', [
            'rows' => $this->departments->visibleDepartments($this->getUser()),
        ]);
    }

    /**
     * Organizational departments are admin-only.
     *
     * Each member section owns a `<key>_q` and a `<key>_page` query parameter, and links and forms
     * carry the other sections' parameters through, so paging or searching one table leaves the rest
     * of the page exactly where the viewer left it. Only the known parameters are carried, so an
     * arbitrary query string cannot be reflected back into the page's own links. Page numbers are
     * read with a cast rather than getInt(), so a blank or malformed one in a hand-edited URL falls
     * back to the first page instead of answering 400.
     */
    #[Route('/departments/{id}', name: 'app_department_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(#[MapEntity(mapping: ['id' => 'uuid'])] Department $department, Request $request): Response
    {
        if ($department->isOrganizational() && !$this->isGranted('global:admin')) {
            throw $this->createAccessDeniedException();
        }

        $members = $this->departments->members($department);
        $everyone = array_merge($members['managers'], $members['shiftManagers'], $members['staff'], $members['nonStaff']);

        $this->contacts->primeMembership($department, array_map(static fn (User $u): int => $u->getId(), $everyone));

        $bySection = [
            'managers' => array_merge($members['managers'], $members['shiftManagers']),
            'staff' => $members['staff'],
            'nonstaff' => $members['nonStaff'],
        ];

        $carried = [];
        foreach (array_keys($bySection) as $key) {
            foreach ([$key.'_q', $key.'_page'] as $name) {
                $value = $request->query->get($name);
                if (\is_string($value) && $value !== '') {
                    $carried[$name] = $value;
                }
            }
        }

        $sections = [];
        $visible = [];
        foreach ($bySection as $key => $users) {
            $sections[$key] = $this->departments->paginateMembers(
                $users,
                (string) $request->query->get($key.'_q', ''),
                max(1, (int) $request->query->get($key.'_page', 1)),
            );

            $sections[$key]['key'] = $key;
            $sections[$key]['keep'] = array_diff_key($carried, [$key.'_page' => true]);
            $sections[$key]['formKeep'] = array_diff_key($carried, [$key.'_page' => true, $key.'_q' => true]);

            $visible = array_merge($visible, $sections[$key]['items']);
        }

        $stats = $this->departments->statsFor($visible);
        $contactMethods = [];
        foreach ($visible as $user) {
            $contactMethods[$user->getId()] = $this->contacts->methodsFor($user, $department);
        }

        return $this->render('department/show.html.twig', [
            'department' => $department,
            'members' => $members,
            'sections' => $sections,
            'staffing' => $this->departments->staffing($department),
            'stats' => $stats,
            'contactMethods' => $contactMethods,
            'pending' => $this->delegatedRequests->findPendingByDepartment($department),
            'positions' => DepartmentPosition::cases(),
        ]);
    }

    #[Route('/departments/{id}/assignable-search', name: 'app_department_assignable_search', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('department:member:manage')]
    public function assignableSearch(#[MapEntity(mapping: ['id' => 'uuid'])] Department $department, Request $request, UserSearchResultFormatter $formatter): JsonResponse
    {
        $this->denyAccessUnlessGranted('department:member:manage', $department);

        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return new JsonResponse(['results' => []]);
        }

        $members = $this->departments->members($department);
        $seen = [];
        foreach (array_merge($members['managers'], $members['shiftManagers'], $members['staff'], $members['nonStaff']) as $member) {
            $seen[$member->getId()] = true;
        }

        $matches = array_filter(
            $this->users->searchByName($q),
            static fn (User $user): bool => !isset($seen[$user->getId()]) && !$user->isSsoManaged(),
        );

        return new JsonResponse($formatter->results($matches));
    }
}
