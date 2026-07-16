<?php

declare(strict_types=1);

namespace App\Sso;

use App\Entity\PersonalData;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Log\LoggerInterface;

/**
 * Looks up a user's convention registration number from the external registration API,
 * authenticating the call with the user's own freshly issued OAuth access token, and
 * records it on their personal data.
 *
 * The registration system is authoritative and the number never changes, so an existing
 * value is left untouched; a missing/zero/empty response is a valid "not registered yet"
 * answer and simply leaves the number blank. A lookup failure must never block login, so
 * every error here is swallowed after logging.
 */
final class RegistrationNumberFetcher
{
    public function __construct(
        private readonly RegistrationApiSettings $settings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function updateFor(User $user, GenericProvider $provider, AccessTokenInterface $token): void
    {
        $url = $this->settings->apiUrl();
        if ($url === null) {
            return;
        }

        $personal = $user->getPersonalData();
        if ($personal instanceof PersonalData && $personal->getBadgeNumber() !== null) {
            return;
        }

        $number = $this->query($provider, $token, $url);
        if ($number === null) {
            return;
        }

        $personal ??= new PersonalData($user);
        $personal->setBadgeNumber($number);
        $user->setPersonalData($personal);
        $this->em->flush();
    }

    private function query(GenericProvider $provider, AccessTokenInterface $token, string $url): ?int
    {
        try {
            $request = $provider->getAuthenticatedRequest('GET', $url, $token);
            $response = $provider->getParsedResponse($request);
        } catch (\Throwable $e) {
            $this->logger->error('Registration-number lookup failed: {reason}', ['reason' => $e->getMessage(), 'exception' => $e]);

            return null;
        }

        // The registration API answers with { "ids": [ <regnum>, ... ] }; the first id is the number.
        if (!\is_array($response) || !isset($response['ids'][0]) || !is_numeric($response['ids'][0])) {
            return null;
        }

        $number = (int) $response['ids'][0];

        return $number > 0 ? $number : null;
    }
}
