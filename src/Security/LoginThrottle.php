<?php

namespace App\Security;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\LoginAttempt;
use App\Entity\LoginLockout;
use App\Repository\LoginAttemptRepository;
use App\Repository\LoginLockoutRepository;
use App\Security\Exception\AccountLockedOutException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Brute-force protection for username+password logins.
 *
 * Two independent counters run over a sliding window:
 *
 *  - **Client address.** Enough failures from one address lock that address, whatever usernames it
 *    tries. This is what stops password spraying across many accounts.
 *  - **Account.** Enough failures against one username lock that account, but only once they arrive
 *    from more than one address. A single source is either a volunteer who forgot their password or
 *    an attacker the address lockout already stopped; locking the account on one source would let
 *    anyone who knows a username keep its owner out at will.
 *
 * While a lockout holds, authentication fails even for the correct password, and the caller is told
 * only that the credentials were wrong - see {@see AccountLockedOutException}.
 *
 * State lives in the database rather than the cache pool on purpose: the app cache is filesystem
 * backed, so under Docker/Kubernetes each replica would keep its own counters and an attacker would
 * get the full allowance again on every container they happened to land on.
 *
 * SSO logins never reach this service, so an identity-provider sign-in is unaffected by a lockout.
 */
final class LoginThrottle
{
    /** Failures inside WINDOW_MINUTES that trigger a lockout. */
    public const MAX_FAILURES = 3;

    /** Sliding window the failures are counted over, in minutes. */
    public const WINDOW_MINUTES = 15;

    /** How long a lockout holds, in minutes. */
    public const LOCKOUT_MINUTES = 30;

    /** Distinct client addresses required before the account itself - not just the address - locks. */
    public const MIN_SOURCES_FOR_ACCOUNT_LOCK = 2;

    /** Narrowest column the attempted identifier reaches: the audit log's actor_username. */
    private const MAX_SUBJECT_LENGTH = 128;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoginAttemptRepository $attempts,
        private readonly LoginLockoutRepository $lockouts,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * @throws AccountLockedOutException when either the account or the client address is timed out
     */
    public function assertNotLocked(string $username, ?string $ip): void
    {
        $now = $this->clock->now();

        // The address is checked first so that an attacker cycling usernames is stopped even when
        // none of the names they try exists.
        if ($this->isLocked(LoginLockout::SCOPE_IP, $this->hashIp($ip), $now)) {
            throw new AccountLockedOutException();
        }

        $usernameKey = self::normalise($username);
        if ($usernameKey !== '' && $this->isLocked(LoginLockout::SCOPE_ACCOUNT, $usernameKey, $now)) {
            throw new AccountLockedOutException();
        }
    }

    public function recordFailure(string $username, ?string $ip): void
    {
        $now = $this->clock->now();
        $usernameKey = self::normalise($username);
        $ipHash = $this->hashIp($ip);

        $this->em->persist(new LoginAttempt($usernameKey, $ipHash, $now));
        $this->em->flush();

        $since = $now->sub(new \DateInterval('PT'.self::WINDOW_MINUTES.'M'));

        $ipFailures = $this->attempts->countForIpSince($ipHash, $since);
        if ($ipFailures >= self::MAX_FAILURES) {
            $this->lock(LoginLockout::SCOPE_IP, $ipHash, $now, $ipFailures, 1);
        }

        if ($usernameKey !== '') {
            $accountFailures = $this->attempts->countForUsernameSince($usernameKey, $since);
            $sources = $this->attempts->countDistinctSourcesForUsernameSince($usernameKey, $since);

            if ($accountFailures >= self::MAX_FAILURES && $sources >= self::MIN_SOURCES_FOR_ACCOUNT_LOCK) {
                $this->lock(LoginLockout::SCOPE_ACCOUNT, $usernameKey, $now, $accountFailures, $sources);
            }
        }

        $this->prune($now);
    }

    /**
     * Clears the failure history for an account that has just been signed into successfully.
     *
     * Only the account's own attempts are dropped. The address counter deliberately survives: an
     * attacker who owns a valid account of their own would otherwise reset their allowance at will
     * by logging into it between guesses.
     */
    public function clearFailures(string $username): void
    {
        $usernameKey = self::normalise($username);
        if ($usernameKey === '') {
            return;
        }

        $this->attempts->deleteForUsername($usernameKey);
    }

    /**
     * Lifts a lockout early, on an administrator's instruction.
     *
     * The failures that caused it are dropped along with it. Leaving them would put the subject
     * straight back over the threshold on its very next failed attempt - for the rest of the
     * window, the lift would last exactly one wrong password.
     */
    public function release(LoginLockout $lockout): void
    {
        $scope = $lockout->getScope();
        $subject = $lockout->getSubject();

        $this->em->remove($lockout);
        if ($scope === LoginLockout::SCOPE_ACCOUNT) {
            $this->attempts->deleteForUsername($subject);
        } else {
            $this->attempts->deleteForIp($subject);
        }
        $this->em->flush();

        $this->audit->log(AuditEvents::SECURITY, AuditEvents::LOGIN_LOCKOUT_CLEARED, [
            'resourceType' => 'LoginLockout',
            'resourceId' => $subject,
            'details' => [
                'scope' => $scope,
                'subject' => $scope === LoginLockout::SCOPE_ACCOUNT ? $subject : null,
            ],
        ]);
    }

    private function isLocked(string $scope, string $subject, \DateTimeImmutable $now): bool
    {
        return $this->lockouts->findOneFor($scope, $subject)?->isActiveAt($now) === true;
    }

    private function lock(string $scope, string $subject, \DateTimeImmutable $now, int $failures, int $sources): void
    {
        $until = $now->add(new \DateInterval('PT'.self::LOCKOUT_MINUTES.'M'));

        $existing = $this->lockouts->findOneFor($scope, $subject);
        if ($existing !== null) {
            $existing->extend($until, $failures, $sources);
            $this->em->flush();

            return;
        }

        $this->lockouts->insertOrExtend(
            $scope,
            $subject,
            Uuid::v4(),
            $now,
            $until,
            $failures,
            $sources,
        );

        $this->audit->system(AuditEvents::SECURITY, AuditEvents::LOGIN_LOCKED, [
            'actorUsername' => $scope === LoginLockout::SCOPE_ACCOUNT ? $subject : null,
            'resourceType' => 'LoginLockout',
            'resourceId' => $subject,
            'details' => [
                'scope' => $scope,
                'failure_count' => $failures,
                'source_count' => $sources,
                'minutes' => self::LOCKOUT_MINUTES,
            ],
        ]);
    }

    /**
     * Drops rows that can no longer influence a decision. Done inline on each recorded failure so
     * the tables stay small without a scheduled job; a failed login is rare and already writes.
     */
    private function prune(\DateTimeImmutable $now): void
    {
        $this->attempts->deleteOlderThan($now->sub(new \DateInterval('PT'.self::WINDOW_MINUTES.'M')));
        $this->lockouts->deleteExpired($now);
    }

    /**
     * Casing must not create a second bucket, or "Admin" would get a fresh allowance after "admin".
     *
     * The value is also capped, because the submitted identifier is arbitrary caller input and is
     * written to columns that are not: login_attempts.username_key is 180, and the audit log's
     * actor_username and resource_id are 128. An over-long identifier would otherwise fail the
     * insert and take the whole login response down with it - while recording nothing, so the
     * attempt would not be throttled either. Two identifiers long enough to collide after the cut
     * are both far past any real account and merely share a counter, which only throttles harder.
     */
    private static function normalise(string $username): string
    {
        return mb_substr(mb_strtolower(trim($username)), 0, self::MAX_SUBJECT_LENGTH);
    }

    /**
     * The throttle only ever compares two addresses for equality, so the raw value is never stored.
     * Keyed with the app secret so the hashes cannot be matched back to an address list offline.
     */
    private function hashIp(?string $ip): string
    {
        return hash_hmac('sha256', (string) $ip, $this->secret);
    }
}
