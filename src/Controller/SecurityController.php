<?php

namespace App\Controller;

use App\Service\EventConfigStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    /**
     * The login page doubles as the public landing page: it shows the event name,
     * welcome message and timeline above the sign-in form
     */
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, EventConfigStore $config): Response
    {
        // Already logged in? Go straight to the dashboard.
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'eventName' => $config->get(EventConfigStore::KEY_NAME),
            'welcomeMessage' => $config->get(EventConfigStore::KEY_WELCOME_MESSAGE),
            'timeline' => [
                'Buildup starts' => $config->getDate(EventConfigStore::KEY_BUILDUP_START),
                'Event starts' => $config->getDate(EventConfigStore::KEY_EVENT_START),
                'Event ends' => $config->getDate(EventConfigStore::KEY_EVENT_END),
                'Teardown ends' => $config->getDate(EventConfigStore::KEY_TEARDOWN_END),
            ],
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall.');
    }
}
