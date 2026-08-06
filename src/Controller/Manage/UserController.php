<?php

namespace App\Controller\Manage;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Contact;
use App\Entity\Group;
use App\Entity\InviteToken;
use App\Entity\PersonalData;
use App\Entity\Settings;
use App\Entity\State;
use App\Entity\User;
use App\Controller\BanAppealController;
use App\Form\Model\UserInviteData;
use App\Form\UserInviteType;
use App\Gdpr\BanChecker;
use App\Repository\BadgeRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Service\InviteMailer;
use App\Service\UsernameGenerator;
use App\TwoFactor\StepUpGuard;
use App\TwoFactor\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * User administration: list (with PII masked for sub-admins), invite, edit
 * (groups, badges, active) and deactivate. Sub-admins cannot view PII, cannot
 * touch admin/sub-admin accounts, and cannot assign elevated-role groups.
 */
#[Route('/manage/users')]
#[IsGranted('user:view')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly GroupRepository $groups,
        private readonly BadgeRepository $badges,
        private readonly UsernameGenerator $usernames,
        private readonly InviteMailer $inviteMailer,
        private readonly AuditLogger $audit,
        private readonly BanChecker $bans,
        private readonly TwoFactorService $twoFactor,
        private readonly StepUpGuard $stepUp,
        private readonly MailerInterface $mailer,
    ) {
    }

    #[Route('/{id}/reset-2fa', name: 'app_manage_user_reset_2fa', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('global:admin')]
    public function resetTwoFactor(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if ($stepUp = $this->stepUp->guard($request)) {
            return $stepUp;
        }
        if (!$this->isCsrfTokenValid('reset2fa'.$user->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
        }

        $this->twoFactor->disable($user);
        // Critical action: logged as such and the user is notified.
        $this->audit->log(AuditEvents::SECURITY, AuditEvents::UPDATE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['two_factor' => 'reset_by_admin', 'critical' => true],
        ]);
        $this->mailer->send(
            (new Email())->from('noreply@critter.example')->to($user->getEmail())
                ->subject('Your two-factor authentication was reset')
                ->text('An administrator reset the two-factor authentication on your account. Please set it up again. If you did not expect this, contact us immediately.'),
        );
        $this->addFlash('success', new TranslatableMessage('manage.user.flash.two_factor_reset', ['%name%' => $user->getName()]));

        return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
    }

    /**
     * Queue a re-run of onboarding for one user, applied at their next sign-in.
     *
     * Deliberately not applied here: the onboarding gate reads the completed flag
     * on every request and the user provider reloads the user from the database,
     * so clearing it now would drop anyone already signed in into the wizard
     * mid-session. See OnboardingResetSubscriber.
     */
    #[Route('/{id}/reset-onboarding', name: 'app_manage_user_reset_onboarding', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('global:admin')]
    public function resetOnboarding(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if (!$this->isCsrfTokenValid('reset-onboarding'.$user->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_user_index');
        }

        if ($user->isOnboardingResetPending()) {
            $user->cancelOnboardingReset();
            $this->em->flush();
            $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
                'resourceType' => 'User',
                'resourceId' => $user->getId(),
                'details' => ['onboarding' => 'reset_cancelled'],
            ]);
            $this->addFlash('success', new TranslatableMessage('manage.user.flash.onboarding_cancelled', ['%name%' => $user->getName()]));

            return $this->redirectToRoute('app_manage_user_index');
        }

        $user->requestOnboardingReset();
        $this->em->flush();
        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
            'resourceType' => 'User',
            'resourceId' => $user->getId(),
            'details' => ['onboarding' => 'reset_requested'],
        ]);
        $this->addFlash('success', new TranslatableMessage('manage.user.flash.onboarding_forced', ['%name%' => $user->getName()]));

        return $this->redirectToRoute('app_manage_user_index');
    }

    /**
     * Queue a re-run of onboarding for every user - e.g. after the privacy notice
     * or consent text changes and everyone must see it again.
     */
    #[Route('/reset-onboarding-all', name: 'app_manage_user_reset_onboarding_all', methods: ['POST'])]
    #[IsGranted('global:admin')]
    public function resetOnboardingForAll(Request $request): Response
    {
        // No 2FA step-up: this exposes nothing and changes no credential - it only
        // makes people answer the wizard again. Step-up is reserved for credential
        // and security-config actions (resetting someone's 2FA, SSO settings).
        if (!$this->isCsrfTokenValid('reset-onboarding-all', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_manage_user_index');
        }

        $count = $this->users->requestOnboardingResetForAll();
        $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
            'resourceType' => 'User',
            'details' => ['onboarding' => 'reset_requested_for_all', 'users' => $count, 'critical' => true],
        ]);
        $this->addFlash('success', new TranslatableMessage('manage.user.flash.onboarding_forced_all', ['%count%' => $count]));

        return $this->redirectToRoute('app_manage_user_index');
    }

    #[Route('', name: 'app_manage_user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $users = $query !== '' ? $this->users->search($query, 100) : $this->users->findBy([], ['name' => 'ASC'], 100);

        return $this->render('manage/user/index.html.twig', ['users' => $users, 'query' => $query]);
    }

    #[Route('/invite', name: 'app_manage_user_invite', methods: ['GET', 'POST'])]
    #[IsGranted('user:create')]
    public function invite(Request $request): Response
    {
        $data = new UserInviteData();
        $form = $this->createForm(UserInviteType::class, $data, ['available_groups' => $this->assignableGroups()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->bans->isEmailBanned($data->email)) {
                $this->addFlash('danger', BanAppealController::LEDGER_KEEPER);

                return $this->render('manage/user/invite.html.twig', ['form' => $form]);
            }

            $user = new User();
            $user->setName($this->usernames->unique($data->username))
                ->setEmail($data->email)
                ->setApiKey(bin2hex(random_bytes(16)))
                ->setPassword(bin2hex(random_bytes(16))) // unusable until onboarding sets one
                ->setPersonalData((new PersonalData($user))->setFirstName($data->firstName)->setLastName($data->lastName))
                ->setContact(new Contact($user))
                ->setSettings(new Settings($user))
                ->setState(new State($user));

            foreach ($data->groups as $group) {
                $user->addGroup($group);
            }

            $invite = new InviteToken($user, bin2hex(random_bytes(24)));
            $this->em->persist($user);
            $this->em->persist($invite);
            $this->em->flush();

            $this->inviteMailer->send($invite);
            $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::CREATE, [
                'resourceType' => 'User',
                'resourceId' => $user->getId(),
                'details' => ['username' => $user->getName(), 'invited' => true],
            ]);
            $this->addFlash('success', new TranslatableMessage('manage.user.flash.invitation_sent', ['%name%' => $user->getName()]));

            return $this->redirectToRoute('app_manage_user_index');
        }

        return $this->render('manage/user/invite.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'app_manage_user_edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('user:edit')]
    public function edit(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if (!$this->canManage($user)) {
            throw $this->createAccessDeniedException('You cannot manage this account.');
        }

        if ($request->isMethod('POST')) {
            if ($user->canEditFullName() && $request->request->has('first_name')) {
                $personal = $user->getPersonalData() ?? new PersonalData($user);
                $personal->setFirstName($request->request->get('first_name') ?: null)
                    ->setLastName($request->request->get('last_name') ?: null);
                $user->setPersonalData($personal);
            }

            if ($this->isGranted('user:promote')) {
                $groupUuids = array_map(strval(...), (array) $request->request->all('groups'));
                if ($this->grantsElevatedRole($user, $groupUuids)) {
                    // Promoting to an admin/sub-admin role requires step-up; a
                    // non-staff target must be approved by a global admin.
                    if ($stepUp = $this->stepUp->guard($request)) {
                        return $stepUp;
                    }
                    if (!$user->isStaff() && !$this->isGranted('global:admin')) {
                        $this->addFlash('danger', new TranslatableMessage('manage.user.flash.promote_denied'));

                        return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
                    }
                }
                $this->syncGroups($user, $groupUuids);
            }
            if ($this->isGranted('badge:assign')) {
                $this->syncBadges($user, array_map(strval(...), (array) $request->request->all('badges')));
            }

            $state = $user->getState() ?? new State($user);
            $state->setActive($request->request->getBoolean('active'));
            $user->setState($state);

            $this->em->flush();
            $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
                'resourceType' => 'User',
                'resourceId' => $user->getId(),
            ]);
            $this->addFlash('success', new TranslatableMessage('manage.user.flash.updated'));

            return $this->redirectToRoute('app_manage_user_index');
        }

        return $this->render('manage/user/edit.html.twig', [
            'user' => $user,
            'assignableGroups' => $this->assignableGroups(),
            'allBadges' => $this->badges->findAllOrdered(),
        ]);
    }

    #[Route('/{id}/unlink-telegram', name: 'app_manage_user_unlink_telegram', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('user:telegram:admin')]
    public function unlinkTelegram(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user, \App\Telegram\TelegramLinkService $links): Response
    {
        if ($this->canManage($user) && $this->isCsrfTokenValid('untg'.$user->getId(), (string) $request->request->get('_token'))) {
            $links->unlink($user, $this->getUser() instanceof User ? $this->getUser() : null);
            $this->addFlash('success', new TranslatableMessage('manage.user.flash.telegram_unlinked'));
        }

        return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
    }

    #[Route('/{id}/deactivate', name: 'app_manage_user_deactivate', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[IsGranted('user:delete')]
    public function deactivate(Request $request, #[MapEntity(mapping: ['id' => 'uuid'])] User $user): Response
    {
        if ($this->canManage($user) && $this->isCsrfTokenValid('deactivate'.$user->getId(), (string) $request->request->get('_token'))) {
            $state = $user->getState() ?? new State($user);
            $state->setActive(false);
            $user->setState($state);
            $this->em->flush();
            $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
                'resourceType' => 'User', 'resourceId' => $user->getId(), 'details' => ['active' => false],
            ]);
            $this->addFlash('success', new TranslatableMessage('manage.user.flash.deactivated'));
        }

        return $this->redirectToRoute('app_manage_user_index');
    }

    /**
     * Sub-admins may not manage admin/sub-admin accounts (only global admins can).
     */
    private function canManage(User $target): bool
    {
        if ($this->isGranted('global:admin')) {
            return true;
        }

        return array_intersect(['ROLE_ADMIN', 'ROLE_SUBADMIN'], $target->getRoles()) === [];
    }

    /** @return Group[] groups the current user is allowed to assign */
    private function assignableGroups(): array
    {
        $all = $this->groups->findBy([], ['name' => 'ASC']);
        if ($this->isGranted('global:admin')) {
            return $all;
        }

        // Sub-admins cannot grant elevated-role groups.
        return array_values(array_filter(
            $all,
            static fn (Group $g): bool => !\in_array($g->getRole(), ['ROLE_ADMIN', 'ROLE_SUBADMIN'], true),
        ));
    }

    /**
     * Whether the submitted selection newly grants an admin/sub-admin role.
     *
     * @param string[] $groupUuids
     */
    private function grantsElevatedRole(User $user, array $groupUuids): bool
    {
        $current = [];
        foreach ($user->getGroups() as $group) {
            $current[(string) $group->getUuid()] = true;
        }
        foreach ($this->assignableGroups() as $group) {
            if (\in_array((string) $group->getUuid(), $groupUuids, true)
                && !isset($current[(string) $group->getUuid()])
                && \in_array($group->getRole(), ['ROLE_ADMIN', 'ROLE_SUBADMIN'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param string[] $groupUuids */
    private function syncGroups(User $user, array $groupUuids): void
    {
        $assignableByUuid = [];
        foreach ($this->assignableGroups() as $g) {
            $assignableByUuid[(string) $g->getUuid()] = $g;
        }

        // Remove only assignable groups the user no longer has selected; leave
        // groups outside the editor's authority untouched.
        foreach ($user->getGroups() as $group) {
            if (isset($assignableByUuid[(string) $group->getUuid()]) && !\in_array((string) $group->getUuid(), $groupUuids, true)) {
                $user->removeGroup($group);
            }
        }
        foreach ($groupUuids as $uuid) {
            if (isset($assignableByUuid[$uuid])) {
                $user->addGroup($assignableByUuid[$uuid]);
            }
        }
    }

    /** @param string[] $badgeUuids */
    private function syncBadges(User $user, array $badgeUuids): void
    {
        foreach ($this->badges->findAllOrdered() as $badge) {
            $selected = \in_array((string) $badge->getUuid(), $badgeUuids, true);
            if ($selected && !$user->hasBadge($badge)) {
                $user->addBadge($badge);
            } elseif (!$selected && $user->hasBadge($badge)) {
                $user->removeBadge($badge);
            }
        }
    }
}
