<?php

namespace App\Mercure;

use App\Entity\User;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Mints the subscriber token the browser presents to the Mercure hub.
 *
 * The token names every topic the user may receive, and the hub delivers nothing else. Because it is
 * signed with a secret the browser never sees, no amount of editing the page, the cookie or the
 * EventSource URL can widen it. That is the whole security model of the live transport.
 *
 * Two properties are load-bearing:
 *
 *  - It is signed with MERCURE_SUBSCRIBER_JWT_SECRET, which is NOT the publisher secret. A token
 *    that leaves the server can then never be used to publish, whatever else goes wrong.
 *  - It expires in five minutes and is re-minted on every page render and every heartbeat, with the
 *    topic list recomputed from current permissions. That is the revocation window: someone removed
 *    from a department keeps matching its topic for at most five minutes, during which they receive
 *    change signals but no data, because the follow-up request is authorized afresh.
 */
final class SubscriberCookieFactory
{
    public const COOKIE_NAME = 'mercureAuthorization';

    /** Matches the hub's mount point, so the token is not attached to ordinary application requests. */
    public const COOKIE_PATH = '/.well-known/mercure';

    public const TTL_SECONDS = 300;

    /**
     * Browsers drop a cookie over roughly 4 KB. A token that large would fail silently - the page
     * would simply never receive updates - so it is logged instead.
     */
    private const SIZE_WARNING_BYTES = 3500;

    public function __construct(
        private readonly TopicBuilder $topics,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[\SensitiveParameter]
        private readonly string $subscriberSecret,
    ) {
    }

    /**
     * The claim carries `subscribe` only. A subscriber token that also carried `publish` would let
     * any signed-in user write to the hub.
     *
     * The cookie is a session cookie: it dies with the browser session, and is replaced long before
     * then.
     */
    public function create(User $user, bool $secure): Cookie
    {
        $now = $this->clock->now();
        $topics = $this->topics->forUser($user);

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt($now)
            ->expiresAt($now->modify('+'.self::TTL_SECONDS.' seconds'))
            ->withClaim('mercure', ['subscribe' => $topics])
            ->getToken(new Sha256(), InMemory::plainText($this->subscriberSecret))
            ->toString();

        if (\strlen($token) > self::SIZE_WARNING_BYTES) {
            $this->logger->warning('Mercure subscriber token is close to the browser cookie limit.', [
                'bytes' => \strlen($token),
                'topics' => \count($topics),
                'user' => (string) $user->getUuid(),
            ]);
        }

        return Cookie::create(self::COOKIE_NAME)
            ->withValue($token)
            ->withPath(self::COOKIE_PATH)
            ->withHttpOnly(true)
            ->withSecure($secure)
            ->withSameSite(Cookie::SAMESITE_STRICT)
            ->withExpires(0);
    }

    /** Clears the token, so a signed-out browser stops being able to reach the hub. */
    public function clear(): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue('')
            ->withPath(self::COOKIE_PATH)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT)
            ->withExpires(1);
    }
}
