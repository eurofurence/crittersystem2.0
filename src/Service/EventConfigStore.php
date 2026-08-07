<?php

namespace App\Service;

use App\Audit\AuditEvents;
use App\Audit\AuditLogger;
use App\Entity\EventConfig;
use App\Repository\EventConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Read/write access to the event_config key-value store
 *
 * Values are persisted as JSON; callers deal in plain PHP scalars/arrays.
 * Well-known keys are exposed as constants so other services can share them.
 */
class EventConfigStore implements ResetInterface
{
    public const KEY_NAME = 'event.name';
    public const KEY_WELCOME_MESSAGE = 'event.welcome_message';
    public const KEY_ACCESS_MODE = 'event.access_mode';
    public const KEY_BUILDUP_START = 'event.buildup_start';
    public const KEY_EVENT_START = 'event.event_start';
    public const KEY_EVENT_END = 'event.event_end';
    public const KEY_TEARDOWN_END = 'event.teardown_end';
    public const KEY_DEFAULT_THEME = 'theme.default';

    /*
     * Identity-provider role IDs that lift an SSO user above plain staff in every department they are
     * mapped into. They hold raw IdP identifiers, so the page that edits them is admin-only and
     * step-up guarded - see App\Controller\Admin\SsoRoleController.
     */
    public const KEY_SSO_ROLE_DEPARTMENT_MANAGER = 'sso.role.department_manager';
    public const KEY_SSO_ROLE_SHIFT_MANAGER = 'sso.role.shift_manager';

    /*
     * Identity-provider role IDs that make an SSO user a global admin or sub admin across the whole
     * app (not scoped to a department). Holding the global-admin role wins over the sub-admin role.
     * Same handling as the department role IDs above - raw IdP identifiers, admin-only, step-up
     * guarded. See App\Sso\SsoGlobalRoles.
     */
    public const KEY_SSO_ROLE_GLOBAL_ADMIN = 'sso.role.global_admin';
    public const KEY_SSO_ROLE_SUB_ADMIN = 'sso.role.sub_admin';

    /*
     * Endpoint of the external registration/attendee API queried (with the user's own OAuth token)
     * right after SSO login to learn their convention registration number. Blank disables the lookup.
     * Edited on the step-up-guarded /admin/sso page - see App\Sso\RegistrationApiSettings.
     */
    public const KEY_SSO_BADGE_API_URL = 'sso.badge_number_api_url';

    /*
     * Whether the login page still offers username+password sign-in once SSO is carrying the flow.
     * Read through App\Security\LocalLoginPolicy, never directly: the policy keeps the setting from
     * locking everyone out (it is ignored while SSO is off, and admins always keep password access).
     */
    public const KEY_PASSWORD_LOGIN_ENABLED = 'login.password_enabled';

    // Display / regional settings. These control how dates and
    // times are rendered for everyone, server-side, regardless of the viewer's
    // browser locale or timezone. See {@see \App\Service\DisplaySettings}.
    public const KEY_TIMEZONE = 'display.timezone';
    public const KEY_DATE_FORMAT = 'display.date_format';
    public const KEY_TIME_FORMAT = 'display.time_format';
    public const KEY_DATETIME_FORMAT = 'display.datetime_format';

    public const DEFAULT_TIMEZONE = 'UTC';
    public const DEFAULT_DATE_FORMAT = 'D, d M Y';
    public const DEFAULT_TIME_FORMAT = 'H:i';
    public const DEFAULT_DATETIME_FORMAT = 'D, d M Y H:i';

    public const ACCESS_MODES = ['public', 'staff', 'admin'];

    // Operational configuration, all admin-editable via /manage/operations.
    // Defaults must never be hard-coded at call sites - read them from here.
    public const KEY_BAN_NOSHOW_THRESHOLD = 'ban.noshow_threshold';
    public const KEY_BAN_SCREEN_MESSAGE = 'ban.screen_message';
    public const KEY_MESSAGES_ENABLED = 'messages.enabled';
    public const KEY_INFODESK_WELCOME = 'infodesk.welcome_message';
    public const KEY_INFODESK_FINALIZATION = 'infodesk.finalization_message';
    public const KEY_INFODESK_CLAIM_TIMEOUT = 'infodesk.claim_timeout';
    public const KEY_MESSAGE_EDIT_WINDOW = 'messages.edit_window';
    public const KEY_CALL_RESPONSE_TIMEOUT = 'call.response_timeout';
    public const KEY_CALL_MANAGER_LEAD = 'call.manager_lead';
    public const KEY_SHIFT_REMINDER_LEAD = 'shift.reminder_lead';
    public const KEY_HOURS_RECOMMENDED_MAX = 'hours.recommended_max';
    public const KEY_MEMBERSHIP_AUTO_FROM_LINKS = 'membership.auto_from_links';
    public const KEY_HOURS_NIGHT_START = 'hours.night_start';
    public const KEY_HOURS_NIGHT_END = 'hours.night_end';
    public const KEY_HOURS_NIGHT_MULTIPLIER = 'hours.night_multiplier';
    public const KEY_HOURS_NOSHOW_MULTIPLIER = 'hours.noshow_multiplier';

    /*
     * How long a session survives without a request. It lives here rather than in framework.yaml because
     * `framework.session.*` is compile-time container configuration and cannot read the database; see
     * App\EventSubscriber\SessionIdleSubscriber, which enforces it per request.
     */
    public const KEY_SESSION_IDLE_MINUTES = 'session.idle_minutes';

    public const DEFAULT_BAN_NOSHOW_THRESHOLD = 2;       // no-show shifts
    public const DEFAULT_BAN_SCREEN_MESSAGE = "Your account is suspended :)";       // Ban Message
    public const DEFAULT_INFODESK_CLAIM_TIMEOUT = 300;   // seconds
    public const DEFAULT_INFODESK_WELCOME = "Welcome to Info Desk Support - What can we do to help?";  // Initial Message for Chat
    public const DEFAULT_INFODESK_FINALIZATION = "Chat closed - Thanks for your contact";  // Closing message for Chat
    public const DEFAULT_MESSAGE_EDIT_WINDOW = 60;       // seconds
    public const DEFAULT_CALL_RESPONSE_TIMEOUT = 600;    // seconds
    public const DEFAULT_CALL_MANAGER_LEAD = 300;        // seconds before shift start
    public const DEFAULT_SHIFT_REMINDER_LEAD = 1800;     // seconds
    public const DEFAULT_HOURS_RECOMMENDED_MAX = 20;     // hours, warning threshold
    public const DEFAULT_HOURS_NIGHT_START = 2;          // hour of day (inclusive)
    public const DEFAULT_HOURS_NIGHT_END = 8;            // hour of day (exclusive)
    public const DEFAULT_HOURS_NIGHT_MULTIPLIER = 2.0;   // Night factor
    public const DEFAULT_HOURS_NOSHOW_MULTIPLIER = -2.0; // No-show Multiplier
    public const DEFAULT_SESSION_IDLE_MINUTES = 60;      // Minutes without a request

    /**
     * Values already read, so a screen that asks the same question on every row asks the database
     * once. A shift grid reads the event dates for each of its shifts, which alone was two hundred
     * and fifty queries on a busy day.
     *
     * Bounded to one request: Symfony resets services implementing ResetInterface between requests
     * and between messenger messages, so a long-lived worker cannot serve a value that another
     * process has since changed.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    public function __construct(
        private readonly EventConfigRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
    ) {
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!\array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->repository->findOneByKey($key)?->getValue();
        }

        return $this->memo[$key] ?? $default;
    }

    /**
     * Read a value stored as an ISO-8601 string back as a date, or null when the
     * key is unset/blank or the stored value cannot be parsed.
     */
    public function getDate(string $key): ?\DateTimeImmutable
    {
        $value = $this->get($key);
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            // Normalise to the *named* UTC zone. Stored values are ISO-8601 with
            // a "+00:00" offset, which would otherwise yield a DateTimeImmutable
            // whose timezone name is "+00:00" - that mismatches the UTC
            // model_timezone of the event-config form fields and makes Symfony
            // Form throw. setTimezone() keeps the instant and fixes the name.
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public function getInt(string $key, int $default): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function getFloat(string $key, float $default): float
    {
        $value = $this->get($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function getBool(string $key, bool $default): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : filter_var($value, \FILTER_VALIDATE_BOOL);
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        return \is_string($value) ? $value : $default;
    }

    /**
     * Queue a value for the given key. Call {@see flush()} to persist.
     *
     * Every write is audited HERE rather than in the controllers, because this store is the choke
     * point every config screen goes through (/manage/configuration, /manage/event-config,
     * /manage/operations). Auditing at the store means a new config screen cannot forget to.
     *
     * The old value is recorded alongside the new one, so the trail answers "who changed it, from
     * what, to what".
     */
    public function set(string $key, mixed $value): void
    {
        $config = $this->repository->findOneByKey($key);
        $previous = $config?->getValue();
        $this->memo[$key] = $value;

        if ($config === null) {
            $this->em->persist(new EventConfig($key, $value));
        } else {
            if ($previous === $value) {
                return; // Not a change; do not manufacture an audit event for a no-op save.
            }
            $config->setValue($value);
        }

        $this->audit->log(AuditEvents::CONFIGURATION, AuditEvents::UPDATE, [
            'resourceType' => 'EventConfig',
            'resourceId' => $key,
            'details' => [
                'key' => $key,
                'old_value' => self::scalarise($previous),
                'new_value' => self::scalarise($value),
            ],
        ]);
    }

    /** Keep audit details JSON-safe and bounded; a config value can be an array or a long string. */
    private static function scalarise(mixed $value): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return \is_string($value) && mb_strlen($value) > 500 ? mb_substr($value, 0, 500).'…' : $value;
        }

        return json_encode($value);
    }

    public function flush(): void
    {
        $this->em->flush();
    }

    /**
     * All settings as a flat key => value map.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->repository->findAllAsMap();
    }
}
