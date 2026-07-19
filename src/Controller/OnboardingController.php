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
use App\Repository\ConsentTextRepository;
use App\Repository\GroupRepository;
use App\Repository\PrivacyNoticeRepository;
use App\Repository\UserVolunteerTypeRepository;
use App\Repository\VolunteerTypeRepository;
use App\Service\PrivacyNoticeProvider;
use App\Service\TextVariables;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * the Volunteer (or Staff) volunteer type, plus the Volunteer permission group
 * for non-staff.
 */
#[Route('/onboarding')]
#[IsGranted('ROLE_USER')]
final class OnboardingController extends AbstractController
{
    /** Baseline permission group every non-staff user needs to use the app. */
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

    #[Route('/telegram', name: 'app_onboarding_telegram', methods: ['GET', 'POST'])]
    public function telegram(Request $request): Response
    {
        if ($redirect = $this->redirectIfDone()) {
            return $redirect;
        }
        if ($request->isMethod('POST')) {
            // Linking itself is handled by the Telegram feature; this step is
            // always skippable so onboarding is never blocked on it.
            return $this->redirectToRoute('app_onboarding_notifications');
        }

        return $this->render('onboarding/telegram.html.twig');
    }

    #[Route('/notifications', name: 'app_onboarding_notifications', methods: ['GET', 'POST'])]
    public function notifications(Request $request): Response
    {
        if ($redirect = $this->redirectIfDone()) {
            return $redirect;
        }
        $user = $this->currentUser();
        $settings = $user->getSettings() ?? new Settings($user);
        $consent = $user->getConsent() ?? new UserConsent($user);

        if ($request->isMethod('POST')) {
            $settings->setEmailShiftinfo($request->request->getBoolean('email_shifts'));
            $settings->setEmailGoodie($request->request->getBoolean('email_goodies'));
            $consent->setFullNameVisible($request->request->getBoolean('show_name'));
            $consent->setEmailVisible($request->request->getBoolean('show_email'));
            $consent->setPhoneVisible($request->request->getBoolean('show_phone'));
            $user->setSettings($settings)->setConsent($consent);
            $this->em->flush();

            return $this->redirectToRoute('app_onboarding_finish');
        }

        return $this->render('onboarding/notifications.html.twig', ['settings' => $settings, 'consent' => $consent]);
    }

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
            $this->em->flush();
            $this->audit->log(AuditEvents::USER_MANAGEMENT, AuditEvents::UPDATE, ['details' => ['onboarding' => 'completed']]);
            $this->addFlash('success', new TranslatableMessage('onboarding.flash.complete'));

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('onboarding/finish.html.twig', ['user' => $user]);
    }

    /**
     * Give the finishing user everything they need to actually use the app: the
     * default volunteer type (Staff for staff, Volunteer otherwise), confirmed
     * right away because it is granted by the system rather than requested, and
     * - for plain volunteers - the baseline Volunteer permission group, without
     * which they would land on the dashboard with no privileges at all.
     */
    private function assignDefaults(User $user): void
    {
        $name = $user->isStaff() ? 'Staff' : 'Volunteer';
        $type = $this->volunteerTypes->findOneByName($name);
        if ($type !== null) {
            // An SSO group mapping may already have created the membership.
            $membership = $this->memberships->findOneByUserAndType($user, $type);
            if ($membership === null) {
                $membership = new UserVolunteerType($user, $type);
                $this->em->persist($membership);
            }
            if (!$membership->isConfirmed()) {
                $membership->setConfirmedBy($user);
            }
        }

        if (!$user->isStaff()) {
            $group = $this->groups->findOneBySlug(self::VOLUNTEER_GROUP_SLUG);
            if ($group !== null) {
                $user->addGroup($group);
            }
        }
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
            ? $this->redirectToRoute('app_dashboard')
            : null;
    }
}
