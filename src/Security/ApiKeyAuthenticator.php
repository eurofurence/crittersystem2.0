<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Stateless API-key authentication for the ^/api firewall.
 *
 * Accepts the key via "Authorization: Bearer <key>", "X-API-Key: <key>", or
 * "?key=<key>".
 */
class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function supports(Request $request): ?bool
    {
        return $this->extractKey($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $key = $this->extractKey($request);
        if ($key === null) {
            throw new CustomUserMessageAuthenticationException('No API key provided.');
        }

        return new SelfValidatingPassport(new UserBadge($key, function (string $apiKey): \Symfony\Component\Security\Core\User\UserInterface {
            $user = $this->users->findOneByApiKey($apiKey);
            if ($user === null) {
                throw new UserNotFoundException('Invalid API key.');
            }

            return $user;
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['message' => 'Authentication failed.'], Response::HTTP_UNAUTHORIZED);
    }

    private function extractKey(Request $request): ?string
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) {
            $token = trim(substr($authorization, 7));

            return $token !== '' ? $token : null;
        }

        $header = $request->headers->get('X-API-Key');
        if ($header !== null && $header !== '') {
            return $header;
        }

        $query = $request->query->get('key');

        return $query !== null && $query !== '' ? (string) $query : null;
    }
}
