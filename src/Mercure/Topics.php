<?php

namespace App\Mercure;

use App\Entity\Conversation;
use App\Entity\Department;
use App\Entity\User;

/**
 * The topic vocabulary. Every Mercure topic in the application is built here and nowhere else, so
 * the set a subscriber token authorizes and the set a publisher writes to cannot drift apart.
 *
 * Topics carry public UUIDs, never internal primary keys: a topic string reaches the browser and the
 * hub, and a sequential id there would leak record counts and creation order exactly as it would in
 * a URL (see App\Entity\Concern\HasPublicUuid).
 *
 * Topic strings are matched by the hub as literal URIs. They are never handed to a subscriber as a
 * template - see App\Mercure\SubscriberCookieFactory for why a wildcard would be a leak.
 */
final class Topics
{
    private const PREFIX = 'urn:critter:';

    /** Notifications addressed to one user (the navbar bell). */
    public static function userNotifications(User $user): string
    {
        return self::PREFIX.'user:'.$user->getUuid().':notifications';
    }

    /** One user's operational status (free to help, on shift, override expiry). */
    public static function userStatus(User $user): string
    {
        return self::PREFIX.'user:'.$user->getUuid().':status';
    }

    /** Help calls this user is eligible to answer. Fanned out per user, never broadcast. */
    public static function userCalls(User $user): string
    {
        return self::PREFIX.'user:'.$user->getUuid().':calls';
    }

    /** One chat thread. Subscribed by its participants, plus Info Desk for support threads. */
    public static function conversation(Conversation $conversation): string
    {
        return self::PREFIX.'conversation:'.$conversation->getUuid();
    }

    /**
     * Every department's shift activity, as a URI template.
     *
     * The one templated selector in the vocabulary, and only for a grant that is genuinely
     * event-wide: an administrator, or a privilege held through an assignment with no department
     * scope. Enumerating instead is not more secure - it authorizes the same thing - but it is
     * unbounded: 62 departments make a 6.6 KB token, which a browser drops as an oversized cookie
     * and which nginx rejects as an oversized response header, so every page answers 502.
     *
     * It also covers departments created after the token was minted, which enumeration silently
     * does not.
     *
     * NEVER hand this to a scoped user. {@see \App\Mercure\TopicBuilder} decides, from
     * {@see \App\Security\PrivilegeScopeResolver}, and nowhere else.
     */
    public static function allDepartmentShifts(): string
    {
        return self::PREFIX.'department:{departmentUuid}:shifts';
    }

    /** Shift activity within one department: planner, matrix, grid, staffing and the apply screen. */
    public static function departmentShifts(Department $department): string
    {
        return self::PREFIX.'department:'.$department->getUuid().':shifts';
    }

    /**
     * Activity on all-staff shifts, wherever they are run.
     *
     * Staff see other departments' all-staff shifts on the apply screen, so those rows have to
     * update too. Naming the departments that happen to run one is unbounded - an event where most
     * departments do puts every staff member past the browser's 4 KB cookie limit and past nginx's
     * response-header buffer, which answers 502 rather than serving the page. One topic covers them
     * all and grants nothing extra: a subscriber learns only that an all-staff shift changed, which
     * every staff member is entitled to see anyway, and re-reads the grid under their own
     * authorization.
     *
     * Only for staff. {@see \App\Mercure\TopicBuilder} decides.
     */
    public static function allStaffShifts(): string
    {
        return self::PREFIX.'shifts:all-staff';
    }

    /**
     * The Info Desk queue.
     *
     * Info Desk members may watch any support conversation, which would otherwise mean one topic per
     * open thread in every responder's token - unbounded, and a cookie has 4 KB to work with. They
     * get this single topic instead, and the conversation UUID travels in the signal payload. That
     * is safe precisely because only chat:claim holders can subscribe here, which is the same
     * predicate that lets them open the thread.
     */
    public static function infoDeskQueue(): string
    {
        return self::PREFIX.'info-desk:queue';
    }

    /** Site-wide announcements every signed-in user may see (access mode, maintenance). */
    public static function announcements(): string
    {
        return self::PREFIX.'system:announcements';
    }
}
