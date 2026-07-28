<?php

namespace App\Controller\Manage;

use App\Entity\LoginLockout;
use App\Repository\LoginLockoutRepository;
use App\Security\LoginThrottle;
use App\TwoFactor\StepUpGuard;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Login timeouts raised by the brute-force throttle, and the means to lift one early - a volunteer
 * locked out during an event cannot always wait half an hour.
 *
 * Expired rows are never listed: they no longer block anyone, and a list of every account that was
 * ever guessed at is a target list.
 */
#[Route('/manage/login-lockouts')]
#[IsGranted('security:lockout:manage')]
final class LoginLockoutController extends AbstractController
{
    public function __construct(
        private readonly LoginLockoutRepository $lockouts,
        private readonly LoginThrottle $throttle,
        private readonly StepUpGuard $stepUp,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'app_manage_login_lockout_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($stepUp = $this->stepUp->guard($request)) {
            return $stepUp;
        }

        return $this->render('manage/login_lockout/index.html.twig', [
            'lockouts' => $this->lockouts->findActive($this->clock->now()),
            'windowMinutes' => LoginThrottle::WINDOW_MINUTES,
            'maxFailures' => LoginThrottle::MAX_FAILURES,
            'lockoutMinutes' => LoginThrottle::LOCKOUT_MINUTES,
        ]);
    }

    #[Route('/{uuid}/release', name: 'app_manage_login_lockout_release', methods: ['POST'], requirements: ['uuid' => Requirement::UUID])]
    public function release(Request $request, #[MapEntity(mapping: ['uuid' => 'uuid'])] LoginLockout $lockout): Response
    {
        if ($stepUp = $this->stepUp->guard($request)) {
            return $stepUp;
        }

        if (!$this->isCsrfTokenValid('release'.$lockout->getUuid(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_login_lockout_index');
        }

        $this->throttle->release($lockout);
        $this->addFlash('success', new TranslatableMessage('manage.login_lockout.flash.released'));

        return $this->redirectToRoute('app_manage_login_lockout_index');
    }
}
