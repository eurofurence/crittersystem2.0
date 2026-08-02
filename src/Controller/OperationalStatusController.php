<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\OperationalStatusService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Operational Status widget. The GET endpoint returns the inner
 * fragment (re-fetched by the live region on a signal); the POST endpoints mutate
 * the status and return the full Turbo Frame so the change is reflected without
 * a full-page refresh.
 */
#[Route('/status')]
#[IsGranted('ROLE_USER')]
final class OperationalStatusController extends AbstractController
{
    public function __construct(private readonly OperationalStatusService $status)
    {
    }

    #[Route('', name: 'app_operational_status', methods: ['GET'])]
    public function widget(): Response
    {
        return $this->render('operational_status/_content.html.twig', [
            'vm' => $this->status->viewModel($this->currentUser()),
        ]);
    }

    #[Route('/free-to-help', name: 'app_operational_status_set', methods: ['POST'])]
    public function setFreeToHelp(Request $request): Response
    {
        if ($this->isCsrfTokenValid('op_status', (string) $request->request->get('_op_token'))) {
            $minutes = (int) $request->request->get('minutes');
            if (\in_array($minutes, OperationalStatusService::DURATIONS, true)) {
                $this->status->setFreeToHelp($this->currentUser(), $minutes);
            }
        }

        return $this->frame();
    }

    #[Route('/clear', name: 'app_operational_status_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        if ($this->isCsrfTokenValid('op_status', (string) $request->request->get('_op_token'))) {
            $this->status->clear($this->currentUser());
        }

        return $this->frame();
    }

    private function frame(): Response
    {
        return $this->render('operational_status/_widget.html.twig', [
            'vm' => $this->status->viewModel($this->currentUser()),
        ]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
