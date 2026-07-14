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
        $this->addFlash('success', "Two-factor authentication reset for {$user->getName()}.");

        return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
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
            $this->addFlash('success', \sprintf('Invitation sent to "%s".', $user->getName()));

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
                $groupIds = array_map('intval', (array) $request->request->all('groups'));
                if ($this->grantsElevatedRole($user, $groupIds)) {
                    // Promoting to an admin/sub-admin role requires step-up; a
                    // non-staff target must be approved by a global admin.
                    if ($stepUp = $this->stepUp->guard($request)) {
                        return $stepUp;
                    }
                    if (!$user->isStaff() && !$this->isGranted('global:admin')) {
                        $this->addFlash('danger', 'A non-staff user can only be promoted to an elevated role by a global admin.');

                        return $this->redirectToRoute('app_manage_user_edit', ['id' => $user->getUuid()]);
                    }
                }
                $this->syncGroups($user, $groupIds);
            }
            if ($this->isGranted('badge:assign')) {
                $this->syncBadges($user, array_map('intval', (array) $request->request->all('badges')));
            }

            $state = $user->getState() ?? new State($user);
            $state->setActive($request->request->getBoolean('active'));
            $user->setState($state);

            $this->em->flush();
            $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, [
                'resourceType' => 'User',
                'resourceId' => $user->getId(),
            ]);
            $this->addFlash('success', 'User updated.');

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
            $this->addFlash('success', 'Telegram account unlinked.');
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
            $this->addFlash('success', 'User deactivated.');
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
     * @param int[] $groupIds
     */
    private function grantsElevatedRole(User $user, array $groupIds): bool
    {
        $current = [];
        foreach ($user->getGroups() as $group) {
            $current[$group->getId()] = true;
        }
        foreach ($this->assignableGroups() as $group) {
            if (\in_array($group->getId(), $groupIds, true)
                && !isset($current[$group->getId()])
                && \in_array($group->getRole(), ['ROLE_ADMIN', 'ROLE_SUBADMIN'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param int[] $groupIds */
    private function syncGroups(User $user, array $groupIds): void
    {
        $assignable = $this->assignableGroups();
        $assignableById = [];
        foreach ($assignable as $g) {
            $assignableById[$g->getId()] = $g;
        }

        // Remove only assignable groups the user no longer has selected; leave
        // groups outside the editor's authority untouched.
        foreach ($user->getGroups() as $group) {
            if (isset($assignableById[$group->getId()]) && !\in_array($group->getId(), $groupIds, true)) {
                $user->removeGroup($group);
            }
        }
        foreach ($groupIds as $id) {
            if (isset($assignableById[$id])) {
                $user->addGroup($assignableById[$id]);
            }
        }
    }

    /** @param int[] $badgeIds */
    private function syncBadges(User $user, array $badgeIds): void
    {
        foreach ($this->badges->findAllOrdered() as $badge) {
            $selected = \in_array($badge->getId(), $badgeIds, true);
            if ($selected && !$user->hasBadge($badge)) {
                $user->addBadge($badge);
            } elseif (!$selected && $user->hasBadge($badge)) {
                $user->removeBadge($badge);
            }
        }
    }
}
