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
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\Uuid;

/**
 * Global Call for Help and the Bounty Board. Managers/Info
 * Desk trigger and cancel calls; eligible users see and accept/refuse them on the
 * Bounty Board, which follows remaining capacity over Mercure. Acceptance takes a row
 * lock inside a transaction, so a call cannot be filled past its slots.
 */
#[IsGranted('ROLE_USER')]
final class CallController extends AbstractController
{
    public function __construct(private readonly HelpCallService $calls)
    {
    }

    /**
     * The slots box is optional, so clearing it posts an empty string. That is why the count is read
     * with a cast and not getInt(), which rejects an empty string as malformed instead of falling
     * back to the default of 1.
     */
    #[Route('/calls/trigger', name: 'app_call_trigger', methods: ['POST'])]
    #[IsGranted('call:trigger')]
    public function trigger(Request $request, \App\Repository\ShiftRepository $shifts): Response
    {
        $shift = $shifts->findOneByUuid((string) $request->request->get('shift'));
        if ($shift === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('shift:manage', $shift->getDepartment());
        if (!$this->isCsrfTokenValid('call_trigger', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $me = $this->getUser();
        if (!$this->calls->canTriggerNow($me, $shift, $me->isInfoDesk())) {
            $this->addFlash('warning', new TranslatableMessage('call.flash.cannot_trigger'));

            return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
        }

        $slots = max(1, (int) $request->request->get('slots', 1));
        $this->calls->trigger($shift, $me, $slots);
        $this->addFlash('success', new TranslatableMessage('call.flash.sent'));

        return $this->returnTo($request, $shift);
    }

    /**
     * The CSRF token id is keyed by the public uuid, not the primary key: it is rendered into the
     * page, and sequential keys reveal how many calls the event has raised.
     */
    #[Route('/calls/{id}/cancel', name: 'app_call_cancel', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('call:cancel')]
    public function cancel(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] HelpCall $call): Response
    {
        if ($this->isCsrfTokenValid('call_cancel'.$call->getUuid(), (string) $request->request->get('_token'))) {
            $this->calls->cancel($call);
            $this->addFlash('success', new TranslatableMessage('call.flash.cancelled'));
        }

        return $this->returnTo($request, $call->getShift());
    }

    /**
     * Back where the caller came from. The operations board triggers calls too, and sending a wall
     * display to the staffing screen would strand it on a page nobody is watching.
     *
     * The target is not a URL and not a route name: only the board is reachable, and only by naming
     * a department and a date that the board's own action re-checks before rendering anything. That
     * keeps this from becoming an open redirect, and keeps the authorization where it already lives.
     */
    private function returnTo(Request $request, Shift $shift): Response
    {
        $department = (string) $request->request->get('board_department', '');
        $date = (string) $request->request->get('board_date', '');

        if (Uuid::isValid($department) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $this->redirectToRoute('app_board_show', [
                'department' => $department,
                'date' => $date,
                'view' => 'shifts',
            ]);
        }

        return $this->redirectToRoute('app_shift_staffing', ['id' => $shift->getUuid()]);
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
                $this->addFlash('success', new TranslatableMessage('call.flash.accepted', ['%title%' => $call->getShift()->getTitle()]));
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
