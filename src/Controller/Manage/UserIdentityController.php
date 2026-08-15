<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Controller\BanAppealController;
use App\Entity\User;
use App\Gdpr\BanChecker;
use App\Repository\UserRepository;
use App\Service\AccountInvitationService;
use App\TwoFactor\StepUpGuard;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/users')]
#[IsGranted('user:view')]
final class UserIdentityController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccountInvitationService $invitations,
        private readonly BanChecker $bans,
        private readonly AuditLogger $audit,
        private readonly StepUpGuard $stepUp,
    ) {
    }

    #[Route('/{id}/resend-invite', name: 'app_manage_user_resend_invite', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('user:create')]
    public function resendInvite(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if (!$this->canManage($user)) {
            throw $this->createAccessDeniedException('You cannot manage this account.');
        }
        if (!$this->isCsrfTokenValid('resend-invite'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($user->isSsoManaged() || $user->isOnboardingCompleted()) {
            $this->addFlash('warning', 'Only incomplete manually managed accounts can be invited again.');

            return $this->redirectBack($request, $user);
        }
        if ($this->bans->isEmailBanned($user->getEmail())) {
            $this->addFlash('danger', BanAppealController::LEDGER_KEEPER);

            return $this->redirectBack($request, $user);
        }

        $this->invitations->reissue($user);
        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['invitation' => 'reissued'],
        ]);
        $this->addFlash('success', sprintf('Invitation resent to %s.', $user->getEmail()));

        return $this->redirectBack($request, $user);
    }

    #[Route('/{id}/email', name: 'app_manage_user_change_email', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('global:admin')]
    public function changeEmail(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if ($stepUp = $this->stepUp->guard($request)) {
            return $stepUp;
        }
        if (!$this->isCsrfTokenValid('change-email'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($user->isSsoManaged()) {
            $this->addFlash('warning', 'This email address is managed by SSO and must be changed at the identity provider.');

            return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
        }

        $email = trim((string) $request->request->get('email'));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('danger', 'Enter a valid email address.');

            return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
        }

        $duplicate = $this->users->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->andWhere('u.id != :id')
            ->setParameter('email', mb_strtolower($email))
            ->setParameter('id', $user->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($duplicate !== null) {
            $this->addFlash('danger', 'That email address is already in use.');

            return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
        }
        if ($this->bans->isEmailBanned($email)) {
            $this->addFlash('danger', BanAppealController::LEDGER_KEEPER);

            return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
        }

        $user->setEmail($email);
        $this->users->getEntityManager()->flush();

        $reissued = !$user->isOnboardingCompleted();
        if ($reissued) {
            $this->invitations->reissue($user);
        }

        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['email' => 'changed_by_admin', 'invitation_reissued' => $reissued],
        ]);
        $this->addFlash('success', $reissued
            ? 'Email address updated and a fresh invitation was sent.'
            : 'Email address updated.');

        return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
    }

    private function canManage(User $target): bool
    {
        if ($this->isGranted('global:admin')) {
            return true;
        }

        return array_intersect(['ROLE_ADMIN', 'ROLE_SUBADMIN'], $target->getRoles()) === [];
    }

    private function redirectBack(Request $request, User $user): Response
    {
        if ((string) $request->request->get('from') === 'edit') {
            return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
        }

        return $this->redirectToRoute('app_manage_user_index');
    }
}
