<?php

namespace App\Controller;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\User;
use App\Form\LocalLoginType;
use App\Form\Model\LocalLoginData;
use App\Form\Model\RegistrationApiData;
use App\Form\RegistrationApiType;
use App\Service\EventConfigStore;
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
        private readonly EventConfigStore $configStore,
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

    /**
     * The OIDC callback, serving both a real login and the userinfo probe.
     *
     * The probe reuses this callback and redirect URI so the diagnostic needs no extra registration
     * at the provider, and its session flag is consumed up front. A probe only reads the provider's
     * raw claims for display: it must not provision, mutate or re-authenticate, so the admin's
     * existing session is left as it is.
     *
     * The registration-API lookup runs here because the user's own access token authenticates it and
     * is only in scope at this point. It self-guards on configuration and never throws out.
     *
     * A failure comes from the identity provider or the HTTP client, so its message can carry
     * endpoint URLs, client ids and provider internals. That goes to the log for the operator, never
     * onto the login page for whoever is at the browser.
     *
     * Access mode may shut a freshly signed-in user out. They go to the notice with their session
     * kept, so their digital badge stays reachable, rather than to a dashboard the gate would bounce
     * them off.
     */
    #[Route('/login/sso/check', name: 'app_sso_check', methods: ['GET'])]
    public function check(Request $request, Security $security): Response
    {
        if (!$this->config->isEnabled()) {
            throw $this->createNotFoundException('SSO is not enabled.');
        }

        $session = $request->getSession();
        $isProbe = (bool) $session->get('sso_userinfo_probe', false);
        $session->remove('sso_userinfo_probe');

        $state = $request->query->get('state');
        if ($state === null || $state !== $session->get('sso_state')) {
            $this->addFlash('danger', new TranslatableMessage('admin.sso.flash.invalid_state'));

            return $this->redirectToRoute($isProbe ? 'app_admin_sso' : 'app_login');
        }

        try {
            $provider = $this->providerFactory->create();
            $token = $provider->getAccessToken('authorization_code', ['code' => (string) $request->query->get('code')]);
            $rawClaims = $provider->getResourceOwner($token)->toArray();

            if ($isProbe) {
                $session->set('sso_userinfo_result', $rawClaims);

                return $this->redirectToRoute('app_admin_sso');
            }

            $claims = SsoClaims::fromArray($rawClaims);
            $user = $this->provisioner->provision($claims);
            $this->registrationNumbers->updateFor($user, $provider, $token);
        } catch (BannedIdentityException) {
            return $this->redirectToRoute('app_ban_appeal');
        } catch (\Throwable $e) {
            $this->logger->error('SSO login failed: {reason}', ['reason' => $e->getMessage(), 'exception' => $e]);
            if ($isProbe) {
                $this->addFlash('danger', new TranslatableMessage('admin.sso.flash.userinfo_failed'));

                return $this->redirectToRoute('app_admin_sso');
            }
            $this->addFlash('danger', new TranslatableMessage('admin.sso.flash.login_failed'));

            return $this->redirectToRoute('app_login');
        }

        $security->login($user);
        $this->audit->log(AuditEvents::AUTHENTICATION, AuditEvents::LOGIN, [
            'actorUser' => $user,
            'details' => ['method' => 'sso', 'provider' => $this->config->providerLabel()],
        ]);

        if (!$this->accessModeGate->permits($user)) {
            return $this->redirectToRoute('app_system_unavailable');
        }

        return $this->redirectToRoute('app_news_index');
    }

    /**
     * Probe results are pulled from the session and cleared in the same breath: the raw claims carry
     * the admin's own PII (email, name), so they are shown once and never left lingering there.
     */
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

        $localLoginData = new LocalLoginData();
        $localLoginData->passwordLoginEnabled = $this->configStore->getBool(EventConfigStore::KEY_PASSWORD_LOGIN_ENABLED, true);
        $localLoginForm = $this->createForm(LocalLoginType::class, $localLoginData);
        $localLoginForm->handleRequest($request);

        if ($localLoginForm->isSubmitted() && $localLoginForm->isValid()) {
            $this->configStore->set(EventConfigStore::KEY_PASSWORD_LOGIN_ENABLED, $localLoginData->passwordLoginEnabled);
            $this->configStore->flush();
            $this->addFlash('success', new TranslatableMessage('admin.sso.flash.local_login_saved'));

            return $this->redirectToRoute('app_admin_sso');
        }

        $userinfo = $request->getSession()->get('sso_userinfo_result');
        $request->getSession()->remove('sso_userinfo_result');

        return $this->render('admin/sso/status.html.twig', [
            'enabled' => $this->config->isEnabled(),
            'provider' => $this->config->providerLabel(),
            'clientId' => $this->config->truncatedClientId(),
            'redirectUri' => $this->providerFactory->redirectUri(),
            'discovery' => $this->discovery->status(),
            'usesDiscovery' => $this->config->usesDiscovery(),
            'discoveryUrl' => $this->config->discoveryUrl(),
            'registrationApiForm' => $form,
            'localLoginForm' => $localLoginForm,
            'userinfo' => is_array($userinfo) ? $userinfo : null,
        ]);
    }

    /**
     * Diagnostic: re-runs the OIDC authorization flow for the signed-in SSO admin and, on return,
     * shows the provider's raw userinfo claims on the status page (see check()). No token is stored,
     * so this fresh round-trip is the only way to read them on demand.
     */
    #[Route('/admin/sso/userinfo', name: 'app_admin_sso_userinfo', methods: ['GET'])]
    #[IsGranted('config:sso')]
    public function probeUserinfo(Request $request): Response
    {
        if ($stepUp = $this->stepUp->guard($request)) {
            return $stepUp;
        }
        if (!$this->config->isEnabled()) {
            throw $this->createNotFoundException('SSO is not enabled.');
        }

        $user = $this->getUser();
        if (!$user instanceof User || !$user->isSsoManaged()) {
            throw $this->createNotFoundException('Userinfo lookup is only available for SSO-managed accounts.');
        }

        $provider = $this->providerFactory->create();
        $url = $provider->getAuthorizationUrl(['scope' => implode(' ', $this->config->scopes())]);
        $session = $request->getSession();
        $session->set('sso_state', $provider->getState());
        $session->set('sso_userinfo_probe', true);

        return new RedirectResponse($url);
    }
}
