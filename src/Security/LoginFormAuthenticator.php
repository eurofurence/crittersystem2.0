<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    /** Marks the 401 a background request gets when the session is gone, so the client can be certain. */
    public const SESSION_EXPIRED_HEADER = 'X-Session-Expired';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AccessModeGate $accessModeGate,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $username = (string) $request->getPayload()->get('_username');
        $password = (string) $request->getPayload()->get('_password');
        $csrfToken = (string) $request->getPayload()->get('_csrf_token');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return new Passport(
            new UserBadge($username),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if ($user instanceof User) {
            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            // The Access mode may shut this user out. Land them on the notice (which keeps their
            // session so their digital badge stays reachable) rather than a target they cannot open.
            if (!$this->accessModeGate->permits($user)) {
                return new RedirectResponse($this->urlGenerator->generate('app_system_unavailable'));
            }
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }

    /**
     * A background request must never be answered with the login page.
     *
     * The default entry point redirects to /login, and `fetch()` follows redirects, so a polling widget
     * would receive 200 OK carrying the whole login document and inject it into itself. Answer those
     * requests with a bare 401 instead and let the client decide to leave the page; only a real
     * navigation gets the redirect.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (self::isBackgroundRequest($request)) {
            return new Response('', Response::HTTP_UNAUTHORIZED, [self::SESSION_EXPIRED_HEADER => '1']);
        }

        return parent::start($request, $authException);
    }

    /**
     * A request the browser made on the page's behalf (poll, Turbo frame, form post over fetch) rather
     * than a top-level navigation. Symfony also skips saving a target path for these, which is what
     * stops a poll of /status from becoming the place the user is returned to after signing in again.
     */
    public static function isBackgroundRequest(Request $request): bool
    {
        if ($request->isXmlHttpRequest() || $request->headers->has('Turbo-Frame')) {
            return true;
        }

        // Sent by every modern browser; absent on curl and old clients, which then read as a navigation.
        $mode = $request->headers->get('Sec-Fetch-Mode');

        return $mode !== null && $mode !== 'navigate';
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
