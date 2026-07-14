<?php

namespace App\Controller;

use App\Entity\HelpCall;
use App\Entity\Shift;
use App\Entity\User;
use App\Service\Call\HelpCallService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Global Call for Help and the Bounty Board. Managers/Info
 * Desk trigger and cancel calls; eligible users see and accept/refuse them on the
 * Bounty Board, which polls for live capacity. Acceptance is transaction-safe.
 */
#[IsGranted('ROLE_USER')]
final class CallController extends AbstractController
{
    public function __construct(private readonly HelpCallService $calls)
    {
    }

    #[Route('/calls/trigger', name: 'app_call_trigger', methods: ['POST'])]
    #[IsGranted('call:trigger')]
    public function trigger(Request $request, \App\Repository\ShiftRepository $shifts): Response
    {
        $shift = $shifts->find($request->request->getInt('shift'));
        if ($shift === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('shift:manage', $shift->getDepartment());
        if (!$this->isCsrfTokenValid('call_trigger', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $me = $this->getUser();
        if (!$this->calls->canTriggerNow($me, $shift, $me->isInfoDesk())) {
            $this->addFlash('warning', 'A call cannot be triggered for this shift yet.');

            return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
        }

        $slots = max(1, $request->request->getInt('slots', 1));
        $this->calls->trigger($shift, $me, $slots);
        $this->addFlash('success', 'Call for help sent.');

        return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
    }

    #[Route('/calls/{id}/cancel', name: 'app_call_cancel', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('call:cancel')]
    public function cancel(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] HelpCall $call): Response
    {
        if ($this->isCsrfTokenValid('call_cancel'.$call->getId(), (string) $request->request->get('_token'))) {
            $this->calls->cancel($call);
            $this->addFlash('success', 'Call cancelled.');
        }

        return $this->redirectToRoute('app_shift_staffing', ['id' => $call->getShift()->getUuid()]);
    }

    #[Route('/bounty', name: 'app_bounty_board', methods: ['GET'])]
    #[IsGranted('call:respond')]
    public function bounty(): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        return $this->render('call/bounty.html.twig', [
            'calls' => $this->calls->eligibleActiveCalls($me),
        ]);
    }

    #[Route('/bounty/frame', name: 'app_bounty_frame', methods: ['GET'])]
    #[IsGranted('call:respond')]
    public function frame(): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        return $this->render('call/_bounty_list.html.twig', [
            'calls' => $this->calls->eligibleActiveCalls($me),
        ]);
    }

    #[Route('/calls/{id}/accept', name: 'app_call_accept', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('call:respond')]
    public function accept(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] HelpCall $call): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        if ($this->isCsrfTokenValid('call_respond'.$call->getId(), (string) $request->request->get('_token'))) {
            try {
                $this->calls->accept($call, $me);
                $this->addFlash('success', \sprintf('You accepted "%s". Thank you!', $call->getShift()->getTitle()));
            } catch (\RuntimeException $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_bounty_board');
    }

    #[Route('/calls/{id}/refuse', name: 'app_call_refuse', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('call:respond')]
    public function refuse(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] HelpCall $call): Response
    {
        /** @var User $me */
        $me = $this->getUser();
        if ($this->isCsrfTokenValid('call_respond'.$call->getId(), (string) $request->request->get('_token'))) {
            $this->calls->refuse($call, $me);
        }

        return $this->redirectToRoute('app_bounty_board');
    }
}
