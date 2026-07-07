<?php

namespace App\Controller;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Sso\BannedIdentityException;
use App\Sso\OidcDiscovery;
use App\Sso\OidcProviderFactory;
use App\Sso\SsoClaims;
use App\Sso\SsoConfig;
use App\Sso\SsoUserProvisioner;
use App\TwoFactor\StepUpGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        private readonly AuditLogger $audit,
        private readonly StepUpGuard $stepUp,
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
            $this->addFlash('danger', 'Invalid SSO state. Please try again.');

            return $this->redirectToRoute('app_login');
        }

        try {
            $provider = $this->providerFactory->create();
            $token = $provider->getAccessToken('authorization_code', ['code' => (string) $request->query->get('code')]);
            $claims = SsoClaims::fromArray($provider->getResourceOwner($token)->toArray());
            $user = $this->provisioner->provision($claims);
        } catch (BannedIdentityException) {
            return $this->redirectToRoute('app_ban_appeal');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'SSO login failed: '.$e->getMessage());

            return $this->redirectToRoute('app_login');
        }

        $security->login($user);
        $this->audit->log(AuditEvents::AUTHENTICATION, AuditEvents::LOGIN, [
            'actorUser' => $user,
            'details' => ['method' => 'sso', 'provider' => $this->config->providerLabel()],
        ]);

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/admin/sso', name: 'app_admin_sso', methods: ['GET'])]
    #[IsGranted('config:sso')]
    public function status(Request $request): Response
    {
        if ($stepUp = $this->stepUp->guard($request)) {
            return $stepUp;
        }

        return $this->render('admin/sso/status.html.twig', [
            'enabled' => $this->config->isEnabled(),
            'provider' => $this->config->providerLabel(),
            'clientId' => $this->config->truncatedClientId(),
            'redirectUri' => $this->providerFactory->redirectUri(),
            'discovery' => $this->discovery->status(),
            'usesDiscovery' => $this->config->usesDiscovery(),
            'discoveryUrl' => $this->config->discoveryUrl(),
        ]);
    }
}
