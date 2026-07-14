<?php

namespace App\Controller;

use App\Entity\AssignmentProposal;
use App\Entity\Department;
use App\Entity\User;
use App\Repository\DepartmentRepository;
use App\Service\Assignment\AutoAssignmentPlanner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Automatic assignment proposals: generate a draft proposal for a
 * department, review it, then apply (publish) or discard. Generation never
 * publishes — applying is the manager's explicit action.
 */
#[Route('/manage-shifts/proposals')]
#[IsGranted('assignment:manage')]
final class ProposalController extends AbstractController
{
    public function __construct(
        private readonly AutoAssignmentPlanner $planner,
        private readonly DepartmentRepository $departments,
    ) {
    }

    #[Route('/generate', name: 'app_proposal_generate', methods: ['POST'])]
    public function generate(Request $request): Response
    {
        $department = $this->departments->findOneByUuid((string) $request->request->get('department'));
        if ($department === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('assignment:manage', $department);
        if (!$this->isCsrfTokenValid('proposal_generate', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actor = $this->getUser();
        $proposal = $this->planner->propose($department, $actor instanceof User ? $actor : null);
        $this->addFlash('info', \sprintf('Generated %d suggestion(s) — review before applying.', $proposal->getAssignments()->count()));

        return $this->redirectToRoute('app_proposal_review', ['id' => $proposal->getUuid()]);
    }

    #[Route('/{id}', name: 'app_proposal_review', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function review(#[MapEntity(mapping: ['id' => 'uuid'])] AssignmentProposal $proposal): Response
    {
        $this->denyAccessUnlessGranted('assignment:manage', $proposal->getDepartment());

        return $this->render('assignment/proposal.html.twig', ['proposal' => $proposal]);
    }

    #[Route('/{id}/apply', name: 'app_proposal_apply', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function apply(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] AssignmentProposal $proposal): Response
    {
        $this->denyAccessUnlessGranted('assignment:manage', $proposal->getDepartment());
        if ($this->isCsrfTokenValid('proposal_apply'.$proposal->getId(), (string) $request->request->get('_token'))) {
            $actor = $this->getUser();
            $applied = $this->planner->apply($proposal, $actor instanceof User ? $actor : null);
            $this->addFlash('success', \sprintf('%d assignment(s) applied.', $applied));
        }

        return $this->redirectToRoute('app_manage_shifts_planner', ['department' => $proposal->getDepartment()->getUuid()]);
    }

    #[Route('/{id}/discard', name: 'app_proposal_discard', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function discard(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] AssignmentProposal $proposal): Response
    {
        $this->denyAccessUnlessGranted('assignment:manage', $proposal->getDepartment());
        if ($this->isCsrfTokenValid('proposal_discard'.$proposal->getId(), (string) $request->request->get('_token'))) {
            $this->planner->discard($proposal);
            $this->addFlash('success', 'Proposal discarded.');
        }

        return $this->redirectToRoute('app_manage_shifts_planner', ['department' => $proposal->getDepartment()->getUuid()]);
    }
}
