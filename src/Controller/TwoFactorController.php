<?php

namespace App\Controller;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Service\QrCodeGenerator;
use App\TwoFactor\StepUpManager;
use App\TwoFactor\TwoFactorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Self-service two-factor authentication: TOTP enrolment with recovery codes,
 * disabling, and the step-up re-authentication challenge.
 */
#[Route('/2fa')]
#[IsGranted('ROLE_USER')]
final class TwoFactorController extends AbstractController
{
    private const PENDING_SECRET = '_2fa_pending_secret';

    /**
     * One-time carrier for freshly generated recovery codes. Enabling/regenerating
     * POSTs and then redirects (so Turbo, which discards non-redirect form responses,
     * shows the result); the codes are handed to the show route through the session
     * and cleared on first render so a page refresh cannot re-display them.
     */
    private const SHOW_CODES = '_2fa_show_codes';

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly StepUpManager $stepUp,
        private readonly QrCodeGenerator $qr,
        private readonly MailerInterface $mailer,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_2fa', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('two_factor/index.html.twig', ['user' => $this->user()]);
    }

    #[Route('/setup', name: 'app_2fa_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request): Response
    {
        $user = $this->user();
        if ($user->isTwoFactorEnabled()) {
            return $this->redirectToRoute('app_2fa');
        }

        $session = $request->getSession();
        $secret = (string) $session->get(self::PENDING_SECRET);
        if ($secret === '') {
            $secret = $this->twoFactor->newSecret();
            $session->set(self::PENDING_SECRET, $secret);
        }

        if ($request->isMethod('POST')) {
            $codes = $this->twoFactor->tryEnable($user, $secret, (string) $request->request->get('code'));
            if ($codes === null) {
                $this->addFlash('danger', new TranslatableMessage('two_factor.flash.code_incorrect'));

                // Redirect rather than render: Turbo discards a non-redirect response to a
                // form submission, so a rendered error here would show nothing at all. The
                // pending secret lives in the session, so the same secret/QR is re-shown.
                return $this->redirectToRoute('app_2fa_setup');
            }

            $session->remove(self::PENDING_SECRET);
            $this->stepUp->markVerified();
            $this->notify($user, 'Two-factor authentication enabled', 'Two-factor authentication was just enabled on your account. If this was not you, contact an administrator immediately.');
            $this->audit->log(AuditEvents::SECURITY, AuditEvents::UPDATE, ['details' => ['two_factor' => 'enabled']]);
            $session->set(self::SHOW_CODES, ['codes' => $codes, 'regenerated' => false]);

            return $this->redirectToRoute('app_2fa_recovery_show');
        }

        return $this->render('two_factor/setup.html.twig', [
            'secret' => $secret,
            'qr' => $this->qr->dataUri($this->twoFactor->provisioningUri($user, $secret)),
        ]);
    }

    #[Route('/disable', name: 'app_2fa_disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        $user = $this->user();
        if ($user->mustUseTwoFactor()) {
            $this->addFlash('danger', new TranslatableMessage('two_factor.mandatory'));

            return $this->redirectToRoute('app_2fa');
        }

        if (!$this->twoFactor->verify($user, (string) $request->request->get('code'))) {
            $this->addFlash('danger', new TranslatableMessage('two_factor.flash.disable_invalid_code'));

            return $this->redirectToRoute('app_2fa');
        }

        $this->twoFactor->disable($user);
        $this->stepUp->clear();
        $this->notify($user, 'Two-factor authentication disabled', 'Two-factor authentication was disabled on your account.');
        $this->audit->log(AuditEvents::SECURITY, AuditEvents::UPDATE, ['details' => ['two_factor' => 'disabled']]);
        $this->addFlash('success', new TranslatableMessage('two_factor.flash.disabled'));

        return $this->redirectToRoute('app_2fa');
    }

    #[Route('/recovery-codes/regenerate', name: 'app_2fa_recovery_regenerate', methods: ['POST'])]
    public function regenerateRecoveryCodes(Request $request): Response
    {
        $user = $this->user();
        if (!$user->isTwoFactorEnabled()) {
            return $this->redirectToRoute('app_2fa');
        }

        // Require proof of possession (a current TOTP or an existing recovery
        // code) before issuing a new set, so a hijacked session cannot silently
        // rotate away the legitimate owner's codes.
        if (!$this->twoFactor->verify($user, (string) $request->request->get('code'))) {
            $this->addFlash('danger', new TranslatableMessage('two_factor.flash.regen_invalid_code'));

            return $this->redirectToRoute('app_2fa');
        }

        $codes = $this->twoFactor->regenerateBackupCodes($user);
        $this->stepUp->markVerified();
        $this->notify($user, 'Recovery codes regenerated', 'A new set of two-factor recovery codes was generated for your account and your previous codes no longer work. If this was not you, contact an administrator immediately.');
        $this->audit->log(AuditEvents::SECURITY, AuditEvents::UPDATE, ['details' => ['two_factor' => 'recovery_codes_regenerated']]);
        $request->getSession()->set(self::SHOW_CODES, ['codes' => $codes, 'regenerated' => true]);

        return $this->redirectToRoute('app_2fa_recovery_show');
    }

    #[Route('/recovery-codes', name: 'app_2fa_recovery_show', methods: ['GET'])]
    public function showRecoveryCodes(Request $request): Response
    {
        $data = $request->getSession()->remove(self::SHOW_CODES);
        if (!\is_array($data) || $data['codes'] === []) {
            return $this->redirectToRoute('app_2fa');
        }

        return $this->render('two_factor/backup_codes.html.twig', [
            'codes' => $data['codes'],
            'regenerated' => $data['regenerated'] ?? false,
        ]);
    }

    #[Route('/confirm', name: 'app_2fa_confirm', methods: ['GET', 'POST'])]
    public function confirm(Request $request): Response
    {
        $user = $this->user();
        $return = (string) $request->query->get('return', $request->request->get('return', '/dashboard'));
        if (!str_starts_with($return, '/')) {
            $return = '/dashboard';
        }

        if (!$user->isTwoFactorEnabled()) {
            return $this->redirectToRoute('app_2fa_setup');
        }

        if ($request->isMethod('POST')) {
            if ($this->twoFactor->verify($user, (string) $request->request->get('code'))) {
                $this->stepUp->markVerified();

                return $this->redirect($return);
            }
            $this->addFlash('danger', new TranslatableMessage('two_factor.flash.incorrect_code'));

            // Redirect rather than render: Turbo discards a non-redirect response to a
            // form submission, so a rendered error here would show nothing at all.
            return $this->redirectToRoute('app_2fa_confirm', ['return' => $return]);
        }

        return $this->render('two_factor/confirm.html.twig', ['return' => $return]);
    }

    private function notify(User $user, string $subject, string $body): void
    {
        $this->mailer->send(
            (new Email())->from('noreply@critter.example')->to($user->getEmail())->subject($subject)->text($body),
        );
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
