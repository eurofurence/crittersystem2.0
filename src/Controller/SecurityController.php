<?php

namespace App\Controller;

use App\Security\LocalLoginPolicy;
use App\Service\EventConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class SecurityController extends AbstractController
{
    use TargetPathTrait;

    /**
     * The login page doubles as the public landing page: it shows the event name,
     * welcome message and timeline above the sign-in form
     */
    #[Route('/login', name: 'app_login')]
    public function login(
        Request $request,
        AuthenticationUtils $authenticationUtils,
        EventConfigStore $config,
        LocalLoginPolicy $localLogin,
    ): Response {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        $this->rememberReturnPath($request);

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'passwordLoginEnabled' => $localLogin->isPasswordLoginOffered(),
            'eventName' => $config->get(EventConfigStore::KEY_NAME),
            'welcomeMessage' => $config->get(EventConfigStore::KEY_WELCOME_MESSAGE),
            'timeline' => [
                'login.timeline.buildup_start' => $config->getDate(EventConfigStore::KEY_BUILDUP_START),
                'login.timeline.event_start' => $config->getDate(EventConfigStore::KEY_EVENT_START),
                'login.timeline.event_end' => $config->getDate(EventConfigStore::KEY_EVENT_END),
                'login.timeline.teardown_end' => $config->getDate(EventConfigStore::KEY_TEARDOWN_END),
            ],
        ]);
    }

    /**
     * When a background request is refused with a 401, the client sends the user here with the page they
     * were on in `?return=`. Symfony cannot have saved it itself: it never records a target path for an
     * XHR, and the request that failed was a poll, not the page.
     *
     * Only a path on this site is accepted - an absolute URL, a protocol-relative `//host` or a
     * backslash (which some browsers normalise to `/`) would turn the login page into an open redirect.
     */
    private function rememberReturnPath(Request $request): void
    {
        $return = (string) $request->query->get('return', '');

        if ($return === ''
            || !str_starts_with($return, '/')
            || str_starts_with($return, '//')
            || str_contains($return, '\\')
        ) {
            return;
        }

        $this->saveTargetPath($request->getSession(), 'main', $return);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall.');
    }
}
