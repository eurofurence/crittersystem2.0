<?php

namespace App\Controller;

use App\Entity\Department;
use App\Entity\RequestLink;
use App\Entity\User;
use App\Enum\RequestLinkType;
use App\Repository\DepartmentRepository;
use App\Repository\RequestLinkRepository;
use App\Service\DisplaySettings;
use App\Service\Invite\RequestLinkService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manage department Availability Request and Shift Invitation links. Scoped to
 * departments the manager can manage; creation and revocation
 * are audited by the service.
 */
#[Route('/manage-shifts/links')]
#[IsGranted('invite:manage')]
final class RequestLinkController extends AbstractController
{
    public function __construct(
        private readonly RequestLinkService $service,
        private readonly RequestLinkRepository $links,
        private readonly DepartmentRepository $departments,
        private readonly DisplaySettings $display,
    ) {
    }

    #[Route('', name: 'app_request_links', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $planning = array_values(array_filter(
            $this->departments->findAllOrdered(),
            static fn (Department $d) => !$d->isOrganizational(),
        ));
        if ($planning === []) {
            return $this->render('planner/empty.html.twig');
        }

        $department = ($id = $request->query->get('department'))
            ? ($this->departments->findOneByUuid((string) $id) ?? $planning[0])
            : $planning[0];
        $this->denyAccessUnlessGranted('shift:manage', $department);

        return $this->render('request_link/index.html.twig', [
            'department' => $department,
            'departments' => $planning,
            'links' => $this->links->findForDepartment($department),
            'ssoManaged' => $this->service->isSsoManaged($department),
            'autoMembership' => $this->service->autoMembershipEnabled(),
        ]);
    }

    #[Route('/create', name: 'app_request_links_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $department = $this->departments->findOneByUuid((string) $request->request->get('department'));
        if ($department === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('shift:manage', $department);
        if (!$this->isCsrfTokenValid('request_link', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $type = RequestLinkType::tryFrom((string) $request->request->get('type')) ?? RequestLinkType::AVAILABILITY_REQUEST;
        $expiresAt = null;
        if ($raw = trim((string) $request->request->get('expires_at'))) {
            try {
                $expiresAt = new \DateTimeImmutable($raw, $this->display->timezone());
            } catch (\Exception) {
                $this->addFlash('danger', 'Invalid deadline.');

                return $this->redirectToRoute('app_request_links', ['department' => $department->getUuid()]);
            }
        }

        $user = $this->getUser();
        $this->service->create($type, $department, $user instanceof User ? $user : null, $expiresAt);
        $this->addFlash('success', 'Link created.');

        return $this->redirectToRoute('app_request_links', ['department' => $department->getUuid()]);
    }

    #[Route('/{id}/revoke', name: 'app_request_links_revoke', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function revoke(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] RequestLink $link): Response
    {
        $this->denyAccessUnlessGranted('shift:manage', $link->getDepartment());
        if ($this->isCsrfTokenValid('request_link_revoke'.$link->getId(), (string) $request->request->get('_token'))) {
            $this->service->revoke($link);
            $this->addFlash('success', 'Link revoked.');
        }

        return $this->redirectToRoute('app_request_links', ['department' => $link->getDepartment()->getUuid()]);
    }
}
