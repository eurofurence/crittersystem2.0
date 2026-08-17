<?php

namespace App\Security\Bot;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Service-token authentication for the ^/api/bot firewall.
 *
 * Accepts "Authorization: Bearer <token>" only - no query-string fallback, because
 * a service token in a URL leaks into access logs and proxy history.
 *
 * Fails closed: when BOT_API_TOKEN is unset or empty the whole surface is
 * unreachable, so a misconfigured deployment cannot expose it unauthenticated.
 */
final class BotTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly string $botApiToken)
    {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with((string) $request->headers->get('Authorization', ''), 'Bearer ');
    }

    /**
     * The token comparison is constant-time, so a wrong token cannot be discovered by timing how
     * long the comparison takes.
     */
    public function authenticate(Request $request): Passport
    {
        $presented = trim(substr((string) $request->headers->get('Authorization', ''), 7));

        if ($this->botApiToken === '' || $presented === '') {
            throw new CustomUserMessageAuthenticationException('Bot API token is not configured.');
        }

        if (!hash_equals($this->botApiToken, $presented)) {
            throw new CustomUserMessageAuthenticationException('Invalid bot service token.');
        }

        return new SelfValidatingPassport(
            new UserBadge('telegram-bot', static fn (): BotServiceUser => new BotServiceUser()),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
