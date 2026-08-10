<?php

namespace App\Controller;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\Contact;
use App\Entity\PersonalData;
use App\Entity\Settings;
use App\Entity\User;
use App\Entity\UserConsent;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use App\Repository\ConsentTextRepository;
use App\Repository\GroupRepository;
use App\Repository\PrivacyNoticeRepository;
use App\Repository\TelegramConfigurationRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\EventConfigStore;
use App\Service\PrivacyNoticeProvider;
use App\Service\StaffCheckInService;
use App\Service\TextVariables;
use App\Theme\ThemeCatalog;
use App\Telegram\TelegramLinkService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * First-run onboarding wizard: consent & privacy, profile confirmation, Telegram
 * link (skippable), notification opt-ins, and finally setting a password. On
 * completion the user is flagged onboarded and assigned - already confirmed -
 * the base volunteer type for who they are, plus the baseline permission group.
 */
#[Route('/onboarding')]
#[IsGranted('ROLE_USER')]
final class OnboardingController extends AbstractController
{
    /** Baseline permission group every user needs to use the app, staff included. */
    private const VOLUNTEER_GROUP_SLUG = 'volunteer';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConsentTextRepository $consentTexts,
        private readonly PrivacyNoticeRepository $privacyNotices,
        private readonly PrivacyNoticeProvider $privacyProvider,
        private readonly TextVariables $variables,
        private readonly VolunteerTypeRepository $volunteerTypes,
        private readonly UserVolunteerTypeRepository $memberships,
        private readonly GroupRepository $groups,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLogger $audit,
        private readonly TelegramLinkService $telegramLinks,
        private readonly TelegramConfigurationRepository $telegramConfig,
        private readonly StaffCheckInService $staffCheckIn,
        private readonly ThemeCatalog $themes,
        private readonly EventConfigStore $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'app_onboarding', methods: ['GET', 'POST'])]
    public function consent(Request $request): Response
    {
        if ($redirect = $this->redirectIfDone()) {
            return $redirect;
        }
        $user = $this->currentUser();
        $locale = $user->getSettings()?->getLanguage() ?? 'en_US';
        $consentText = $this->consentTexts->resolve($locale);
        $notice = $this->privacyNotices->current();

        if ($request->isMethod('POST')) {
            if (!$request->request->getBoolean('consent')) {
                $this->addFlash('danger', new TranslatableMessage('onboarding.flash.consent_required'));
            } else {
                $consent = $user->getConsent() ?? new UserConsent($user);
                $consent->grantDataProcessing($locale);
                $user->setConsent($consent);
                $this->em->flush();
                $this->audit->log(AuditEvents::CONSENT, AuditEvents::GRANT, ['details' => ['scope' => 'data_processing', 'locale' => $locale]]);

                return $this->redirectToRoute('app_onboarding_profile');
            }
        }

        $text = $consentText === null ? null : [
            'headerTitle' => $this->variables->apply($consentText->getHeaderTitle()),
            'headerBody' => $this->variables->apply($consentText->getHeaderBody()),
            'checkboxLabel' => $this->variables->apply($consentText->getCheckboxLabel()),
            'footer' => $this->variables->apply($consentText->getFooter()),
        ];

        return $this->render('onboarding/consent.html.twig', [
            'text' => $text,
            'privacyHtml' => $notice ? $this->privacyProvider->render($notice) : null,
        ]);
    }

    #[Route('/profile', name: 'app_onboarding_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        if ($redirect = $this->redirectIfDone()) {
            return $redirect;
        }
        $user = $this->currentUser();
        $personal = $user->getPersonalData() ?? new PersonalData($user);
        $contact = $user->getContact() ?? new Contact($user);

        if ($request->isMethod('POST')) {
            $personal->setPronoun($request->request->get('pronoun') ?: null);
            $contact->setMobile($request->request->get('mobile') ?: null);
            $personal->setPlannedArrivalDate(self::parseDate($request->request->get('planned_arrival')));
            $personal->setPlannedDepartureDate(self::parseDate($request->request->get('planned_departure')));
            if ($user->canEditFullName()) {
                $personal->setFirstName($request->request->get('first_name') ?: null);
                $personal->setLastName($request->request->get('last_name') ?: null);
            }
            $user->setPersonalData($personal)->setContact($contact);
            $this->em->flush();

            return $this->redirectToRoute('app_onboarding_telegram');
        }

        return $this->render('onboarding/profile.html.twig', ['personal' => $personal, 'contact' => $contact, 'user' => $user]);
    }

    /**
     * The step is always skippable, so onboarding is never blocked on it, and it is skipped outright
     * when the feature is off rather than showing a screen whose only action is "continue".
     *
     * A code is prepared on load so the "Open in Telegram" button is ready, reusing a still-valid
     * pending request instead of churning a new one on every reload - the polling script reloads
     * once linking completes.
     */
    #[Route('/telegram', name: 'app_onboarding_telegram', methods: ['GET', 'POST'])]
    public function telegram(Request $request): Response
    {
        if ($redirect = $this->redirectIfDone()) {
            return $redirect;
        }
        if ($request->isMethod('POST')) {
            return $this->redirectToRoute('app_onboarding_notifications');
        }

        $config = $this->telegramConfig->current();
        $enabled = $config?->isEnabled() ?? false;
        $user = $this->currentUser();

        if (!$enabled) {
            return $this->redirectToRoute('app_onboarding_notifications');
        }

        $pending = null;
        if (!$user->isTelegramLinked()) {
            $pending = $this->telegramLinks->pendingFor($user);
            if ($pending === null || $pending->isExpired()) {
                $pending = $this->telegramLinks->startLink($user);
            }
        }

        return $this->render('onboarding/telegram.html.twig', [
            'bot_username' => $config?->getBotUsername(),
            'pending' => $pending,
            'user' => $user,
        ]);
    }

    #[Route('/telegram/status', name: 'app_onboarding_telegram_status', methods: ['GET'])]
    public function telegramStatus(): JsonResponse
    {
        return new JsonResponse(['linked' => $this->currentUser()->isTelegramLinked()]);
    }

    /**
     * Reachability is necessary to run shifts: the volunteer chooses which channel to share, but at
     * least one real, existing channel must be, or the step re-renders instead of advancing.
     */
    #[Route('/notifications', name: 'app_onboarding_notifications', methods: ['GET', 'POST'])]
    public function notifications(Request $request): Response
    {
        if ($redirect = $this->redirectIfDone()) {
            return $redirect;
        }
        $user = $this->currentUser();
        $settings = $user->getSettings() ?? new Settings($user);
        $consent = $user->getConsent() ?? new UserConsent($user);

        $mobile = $user->getContact()?->getMobile();
        $hasPhone = $mobile !== null && $mobile !== '';
        $hasTelegram = $user->isTelegramLinked();
        $hasEmail = $user->getEmail() !== '';

        if ($request->isMethod('POST')) {
            $showName = $request->request->getBoolean('show_name');
            $showEmail = $request->request->getBoolean('show_email');
            $showPhone = $request->request->getBoolean('show_phone');
            $showTelegram = $request->request->getBoolean('show_telegram');

            $reachable = ($showEmail && $hasEmail) || ($showPhone && $hasPhone) || ($showTelegram && $hasTelegram);
            if (!$reachable) {
                $this->addFlash('danger', new TranslatableMessage('onboarding.flash.contact_required'));
            } else {
                $settings->setEmailShiftinfo($request->request->getBoolean('email_shifts'));
                $settings->setEmailGoodie($request->request->getBoolean('email_goodies'));
                $consent->setFullNameVisible($showName);
                $consent->setEmailVisible($showEmail);
                $consent->setPhoneVisible($showPhone);
                $consent->setTelegramVisible($showTelegram);
                $consent->stampVisibilityProvenance($this->privacyNotices->currentVersion());
                $user->setSettings($settings)->setConsent($consent);
                $this->em->flush();
                $this->audit->log(AuditEvents::CONSENT, AuditEvents::UPDATE, ['details' => [
                    'scope' => 'visibility', 'name' => $showName, 'email' => $showEmail, 'phone' => $showPhone, 'telegram' => $showTelegram,
                ]]);

                return $this->redirectToRoute('app_onboarding_theme');
            }
        }

        return $this->render('onboarding/notifications.html.twig', [
            'settings' => $settings,
            'consent' => $consent,
            'hasPhone' => $hasPhone,
            'hasTelegram' => $hasTelegram,
        ]);
    }

    /**
     * Theme choice, with a live preview.
     *
     * The preview costs no JavaScript and stores nothing: {@see \App\Theme\ThemeResolver} honours
     * `?theme=` ahead of the stored setting, so each card is an ordinary link that reloads this step
     * rendered in that theme. Only Confirm writes the choice.
     *
     * The step cannot be skipped, but it also cannot be failed: the event default is preselected, so
     * confirming without touching anything stores that default explicitly.
     */
    #[Route('/theme', name: 'app_onboarding_theme', methods: ['GET', 'POST'])]
    public function theme(Request $request): Response
    {
        if ($redirect = $this->redirectIfDone()) {
            return $redirect;
        }
        $user = $this->currentUser();
        $settings = $user->getSettings() ?? new Settings($user);

        if ($request->isMethod('POST')) {
            $chosen = $this->themes->find((string) $request->request->get('theme', ''))?->slug
                ?? $this->defaultThemeSlug();
            $settings->setTheme($chosen);
            $user->setSettings($settings);
            $this->em->persist($settings);
            $this->em->flush();

            return $this->redirectToRoute('app_onboarding_finish');
        }

        $previewed = $this->themes->find((string) $request->query->get('theme', ''))?->slug;

        return $this->render('onboarding/theme.html.twig', [
            'themes' => $this->themes->all(),
            'selected' => $previewed ?? $settings->getTheme() ?? $this->defaultThemeSlug(),
        ]);
    }

    /** The admin-set default, or the catalog's own fallback when none is configured. */
    private function defaultThemeSlug(): string
    {
        $configured = (string) $this->config->get(EventConfigStore::KEY_DEFAULT_THEME, '');

        return $this->themes->find($configured)?->slug ?? $this->themes->fallback()->slug;
    }

    /**
     * The last step: sets a password for a locally-managed account, then completes onboarding.
     *
     * Staff are sent on to their availability because the planners build the roster from it;
     * everyone else lands on the news.
     */
    #[Route('/finish', name: 'app_onboarding_finish', methods: ['GET', 'POST'])]
    public function finish(Request $request): Response
    {
        if ($redirect = $this->redirectIfDone()) {
            return $redirect;
        }
        $user = $this->currentUser();

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password');
            $confirm = (string) $request->request->get('password_confirm');
            if (!$user->isSsoManaged()) {
                if (\strlen($password) < 8 || $password !== $confirm) {
                    $this->addFlash('danger', new TranslatableMessage('onboarding.flash.password_mismatch'));

                    return $this->render('onboarding/finish.html.twig', ['user' => $user]);
                }
                $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            }

            $this->assignDefaults($user);
            $user->completeOnboarding();
            $this->staffCheckIn->checkInOnboardedStaff($user);
            $this->em->flush();
            $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, ['details' => ['onboarding' => 'completed']]);
            $this->addFlash('success', new TranslatableMessage('onboarding.flash.complete'));

            return $this->redirectToRoute($user->isStaff() ? 'app_availability' : 'app_news_index');
        }

        return $this->render('onboarding/finish.html.twig', ['user' => $user]);
    }

    /**
     * Give the finishing user everything they need to actually use the app: the base volunteer
     * type, and the permission group without which they would arrive at a dashboard they have no
     * privilege to read.
     */
    private function assignDefaults(User $user): void
    {
        $this->assignDefaultVolunteerType($user);
        $this->grantBaselineGroup($user);
    }

    /**
     * The base type for who this user is, confirmed straight away because the system grants it
     * rather than the user asking for it - left unconfirmed it would sit in somebody's queue as a
     * request to approve.
     *
     * An SSO group mapping may have created the membership already, hence the lookup before the
     * insert: the unique index on (user, type) would otherwise abort the whole step.
     *
     * A missing default is logged as an error because nothing else reports it and the result is
     * invisible: the user is told onboarding finished, and is left with no type and no way to be
     * rostered. It means the seeded type was deleted or stripped of its role.
     */
    private function assignDefaultVolunteerType(User $user): void
    {
        $type = $this->volunteerTypes->findDefaultFor($user);
        if ($type === null) {
            $this->logger->error('Onboarding could not assign a default volunteer type.', [
                'user' => (string) $user->getUuid(),
                'role' => $user->isStaff() ? VolunteerType::ROLE_STAFF : VolunteerType::ROLE_VOLUNTEER,
            ]);

            return;
        }

        $membership = $this->memberships->findOneByUserAndType($user, $type);
        if ($membership === null) {
            $membership = new UserVolunteerType($user, $type);
            $this->em->persist($membership);
        }
        if (!$membership->isConfirmed()) {
            $membership->setConfirmedBy($user);
        }
    }

    /**
     * Everyone gets the baseline group, staff included.
     *
     * The positional groups staff hold - department-staff, shift-manager, department-manager - are
     * not supersets of it, so gating this on isStaff() left a locally-onboarded staff member with
     * fewer privileges than the same person arriving through SSO, which grants it unconditionally
     * ({@see \App\Sso\SsoUserProvisioner}). The group carries no role, so granting it widens nobody.
     *
     * Its absence is logged: without it a user reaches the app with no privileges at all, and the
     * only way that happens is the seeded group having been deleted or renamed at creation.
     */
    private function grantBaselineGroup(User $user): void
    {
        $group = $this->groups->findOneBySlug(self::VOLUNTEER_GROUP_SLUG);
        if ($group === null) {
            $this->logger->error('Onboarding could not grant the baseline permission group.', [
                'user' => (string) $user->getUuid(),
                'slug' => self::VOLUNTEER_GROUP_SLUG,
            ]);

            return;
        }

        $user->addGroup($group);
    }

    /**
     * A date from a `<input type="date">`, or null when it was left blank or holds anything that is
     * not a plain calendar date. Travel plans are optional here and a typo must not stop someone
     * finishing onboarding.
     */
    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date === false ? null : $date;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function redirectIfDone(): ?Response
    {
        return $this->currentUser()->isOnboardingCompleted()
            ? $this->redirectToRoute('app_news_index')
            : null;
    }
}
