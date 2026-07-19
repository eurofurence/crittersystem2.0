<?php

namespace App\Controller;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Form\Model\RegistrationApiData;
use App\Form\RegistrationApiType;
use App\Sso\BannedIdentityException;
use App\Sso\OidcDiscovery;
use App\Sso\OidcProviderFactory;
use App\Sso\RegistrationApiSettings;
use App\Sso\RegistrationNumberFetcher;
use App\Sso\SsoClaims;
use App\Sso\SsoConfig;
use App\Sso\SsoUserProvisioner;
use App\Security\AccessModeGate;
use App\TwoFactor\StepUpGuard;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * OIDC SSO login (controller-driven), plus the admin connection-status page.
 * Login start/check live under /login (public); the status page is admin-only
 * and step-up protected once 2FA is in place.
 */
final class SsoController extends AbstractController
{
    public function __construct(
        private readonly SsoConfig $config,
        private readonly OidcProviderFactory $providerFactory,
        private readonly OidcDiscovery $discovery,
        private readonly SsoUserProvisioner $provisioner,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $audit,
        private readonly StepUpGuard $stepUp,
        private readonly RegistrationApiSettings $registrationApi,
        private readonly RegistrationNumberFetcher $registrationNumbers,
        private readonly AccessModeGate $accessModeGate,
    ) {
    }

    #[Route('/login/sso', name: 'app_sso_start', methods: ['GET'])]
    public function start(Request $request): Response
    {
        if (!$this->config->isEnabled()) {
            throw $this->createNotFoundException('SSO is not enabled.');
        }

        $provider = $this->providerFactory->create();
        $url = $provider->getAuthorizationUrl(['scope' => implode(' ', $this->config->scopes())]);
        $request->getSession()->set('sso_state', $provider->getState());

        return new RedirectResponse($url);
    }

    #[Route('/login/sso/check', name: 'app_sso_check', methods: ['GET'])]
    public function check(Request $request, Security $security): Response
    {
        if (!$this->config->isEnabled()) {
            throw $this->createNotFoundException('SSO is not enabled.');
        }

        $state = $request->query->get('state');
        if ($state === null || $state !== $request->getSession()->get('sso_state')) {
            $this->addFlash('danger', new TranslatableMessage('admin.sso.flash.invalid_state'));

            return $this->redirectToRoute('app_login');
        }

        try {
            $provider = $this->providerFactory->create();
            $token = $provider->getAccessToken('authorization_code', ['code' => (string) $request->query->get('code')]);
            $claims = SsoClaims::fromArray($provider->getResourceOwner($token)->toArray());
            $user = $this->provisioner->provision($claims);
            // The user's own access token authenticates the registration-API call; do it here where
            // the token is in scope. It self-guards on configuration and never throws out.
            $this->registrationNumbers->updateFor($user, $provider, $token);
        } catch (BannedIdentityException) {
            return $this->redirectToRoute('app_ban_appeal');
        } catch (\Throwable $e) {
            /*
             * The exception here comes from the identity provider or the HTTP client, so its message can
             * carry endpoint URLs, client ids and provider internals. That belongs in the log, for the
             * operator - never on the login page, for whoever happens to be at the browser.
             */
            $this->logger->error('SSO login failed: {reason}', ['reason' => $e->getMessage(), 'exception' => $e]);
            $this->addFlash('danger', new TranslatableMessage('admin.sso.flash.login_failed'));

            return $this->redirectToRoute('app_login');
        }

        $security->login($user);
        $this->audit->log(AuditEvents::AUTHENTICATION, AuditEvents::LOGIN, [
            'actorUser' => $user,
            'details' => ['method' => 'sso', 'provider' => $this->config->providerLabel()],
        ]);

        // The Access mode may shut this user out; send them to the notice, keeping their session so
        // their digital badge stays reachable, rather than a dashboard the gate would bounce them off.
        if (!$this->accessModeGate->permits($user)) {
            return $this->redirectToRoute('app_system_unavailable');
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/admin/sso', name: 'app_admin_sso', methods: ['GET', 'POST'])]
    #[IsGranted('config:sso')]
    public function status(Request $request): Response
    {
        if ($stepUp = $this->stepUp->guard($request)) {
            return $stepUp;
        }

        $data = new RegistrationApiData();
        $data->apiUrl = $this->registrationApi->apiUrl();
        $form = $this->createForm(RegistrationApiType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->registrationApi->save($data->apiUrl);
            $this->addFlash('success', new TranslatableMessage('admin.sso.flash.registration_saved'));

            return $this->redirectToRoute('app_admin_sso');
        }

        return $this->render('admin/sso/status.html.twig', [
            'enabled' => $this->config->isEnabled(),
            'provider' => $this->config->providerLabel(),
            'clientId' => $this->config->truncatedClientId(),
            'redirectUri' => $this->providerFactory->redirectUri(),
            'discovery' => $this->discovery->status(),
            'usesDiscovery' => $this->config->usesDiscovery(),
            'discoveryUrl' => $this->config->discoveryUrl(),
            'registrationApiForm' => $form,
        ]);
    }
}
