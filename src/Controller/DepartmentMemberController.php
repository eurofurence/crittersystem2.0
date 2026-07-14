<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\DelegatedManagerRequest;
use App\Entity\User;
use App\Enum\DepartmentPosition;
use App\Repository\UserRepository;
use App\Service\DelegatedManagerService;
use App\Service\DepartmentMemberService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Department member actions: placing members and setting their position, delegated shift-manager
 * requests/approvals, and member removal.
 *
 * An SSO-managed user's membership belongs to the identity provider — their position is recomputed
 * from the claimed roles on every sign-in — so the write actions here refuse them rather than make a
 * change that silently reverts on the next login.
 */
#[IsGranted('ROLE_STAFF')]
final class DepartmentMemberController extends AbstractController
{
    public function __construct(
        private readonly DelegatedManagerService $delegated,
        private readonly DepartmentMemberService $members,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/departments/{id}/members/add', name: 'app_department_add_member', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('department:member:manage')]
    public function add(#[MapEntity(mapping: ['id' => 'uuid'])] Department $department, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('addmember'.$department->getId(), (string) $request->request->get('_token'))) {
            return $this->back($department);
        }

        $user = $this->users->findOneBy(['uuid' => $request->request->getString('user')]);
        $position = DepartmentPosition::tryFrom($request->request->getString('position'));

        if (!$user instanceof User || $position === null) {
            $this->addFlash('danger', 'Pick a user and a position.');
        } elseif ($user->isSsoManaged()) {
            $this->addFlash('danger', 'SSO-provisioned users are placed by the identity provider.');
        } else {
            $this->members->setPosition($department, $user, $position);
            $this->addFlash('success', sprintf('%s added as %s.', $user->getName(), lcfirst($position->label())));
        }

        return $this->back($department);
    }

    #[Route('/departments/{id}/members/{userId}/position', name: 'app_department_set_position', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'userId' => Requirement::UUID])]
    #[IsGranted('department:member:manage')]
    public function setPosition(#[MapEntity(mapping: ['id' => 'uuid'])] Department $department, #[MapEntity(mapping: ['userId' => 'uuid'])] User $userId, Request $request): Response
    {
        $position = DepartmentPosition::tryFrom($request->request->getString('position'));

        if ($this->valid($request, 'position', $department, $userId) && $position !== null) {
            if ($userId->isSsoManaged()) {
                $this->addFlash('danger', 'SSO-provisioned positions are set by the identity provider.');
            } else {
                $this->members->setPosition($department, $userId, $position);
                $this->addFlash('success', sprintf('%s is now %s.', $userId->getName(), lcfirst($position->label())));
            }
        }

        return $this->back($department);
    }

    #[Route('/departments/{id}/members/{userId}/request-delegated', name: 'app_department_request_delegated', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'userId' => Requirement::UUID])]
    #[IsGranted('shift:manage')]
    public function requestDelegated(#[MapEntity(mapping: ['id' => 'uuid'])] Department $department, #[MapEntity(mapping: ['userId' => 'uuid'])] User $userId, Request $request): Response
    {
        if ($this->valid($request, 'reqdel', $department, $userId)) {
            $this->delegated->request($department, $userId, $this->getUser());
            $this->addFlash('success', 'Delegated shift-manager promotion requested.');
        }

        return $this->back($department);
    }

    #[Route('/departments/{id}/members/{userId}/remove', name: 'app_department_remove_member', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'userId' => Requirement::UUID])]
    #[IsGranted('department:member:manage')]
    public function remove(#[MapEntity(mapping: ['id' => 'uuid'])] Department $department, #[MapEntity(mapping: ['userId' => 'uuid'])] User $userId, Request $request): Response
    {
        if ($this->valid($request, 'remove', $department, $userId)) {
            if ($userId->isSsoManaged()) {
                $this->addFlash('danger', 'SSO-provisioned memberships cannot be removed here.');
            } else {
                $this->members->remove($department, $userId);
                $this->addFlash('success', sprintf('%s removed from the department.', $userId->getName()));
            }
        }

        return $this->back($department);
    }

    #[Route('/departments/{id}/delegated/{reqId}/{decision<approve|reject>}', name: 'app_department_delegated_decide', methods: ['POST'], requirements: ['id' => Requirement::UUID, 'reqId' => Requirement::UUID])]
    #[IsGranted('delegated:approve')]
    public function decide(#[MapEntity(mapping: ['id' => 'uuid'])] Department $department, #[MapEntity(mapping: ['reqId' => 'uuid'])] DelegatedManagerRequest $reqId, string $decision, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delegated'.$reqId->getId(), (string) $request->request->get('_token'))
            && $reqId->getDepartment()->getId() === $department->getId()) {
            if ($decision === 'approve') {
                $this->delegated->approve($reqId, $this->getUser());
            } else {
                $this->delegated->reject($reqId, $this->getUser());
            }
            $this->addFlash('success', 'Delegated request '.$decision.'d.');
        }

        return $this->back($department);
    }

    private function valid(Request $request, string $action, Department $department, User $user): bool
    {
        return $this->isCsrfTokenValid($action.$department->getId().'-'.$user->getId(), (string) $request->request->get('_token'));
    }

    private function back(Department $department): Response
    {
        return $this->redirectToRoute('app_department_show', ['id' => $department->getUuid()]);
    }
}
