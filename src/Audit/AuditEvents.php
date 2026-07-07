<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Stable vocabulary for the audit log. Event types group related actions;
 * actions name the specific operation; outcomes record success or failure.
 */
final class AuditEvents
{
    // Event types
    public const AUTHENTICATION = 'AUTHENTICATION';
    public const AUTHORIZATION = 'AUTHORIZATION';
    public const USER_MANAGEMENT = 'USER_MANAGEMENT';
    public const ACCESS_CONTROL = 'ACCESS_CONTROL';
    public const DATA_ACCESS = 'DATA_ACCESS';
    public const DATA_EXPORT = 'DATA_EXPORT';
    public const CONFIGURATION = 'CONFIGURATION';
    public const SHIFT = 'SHIFT';
    public const CONSENT = 'CONSENT';
    public const GDPR = 'GDPR';
    public const SECURITY = 'SECURITY';
    public const NOTIFICATION = 'NOTIFICATION';

    // Actions
    public const LOGIN = 'LOGIN';
    public const LOGIN_FAILED = 'LOGIN_FAILED';
    public const LOGOUT = 'LOGOUT';
    public const CREATE = 'CREATE';
    public const READ = 'READ';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';
    public const EXPORT = 'EXPORT';
    public const DOWNLOAD = 'DOWNLOAD';
    public const GRANT = 'GRANT';
    public const REVOKE = 'REVOKE';
    public const EXPIRE = 'EXPIRE';

    // Outcomes
    public const SUCCESS = 'SUCCESS';
    public const FAILURE = 'FAILURE';
}
