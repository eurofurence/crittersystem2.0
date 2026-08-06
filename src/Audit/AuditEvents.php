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
    public const OPERATIONAL_STATUS = 'OPERATIONAL_STATUS';
    public const CHAT = 'CHAT';
    public const CALL = 'CALL';
    public const CERTIFICATION = 'CERTIFICATION';

    // Actions
    public const LOGIN = 'LOGIN';
    public const LOGIN_FAILED = 'LOGIN_FAILED';
    public const LOGOUT = 'LOGOUT';
    public const LOGIN_LOCKED = 'LOGIN_LOCKED';
    public const LOGIN_LOCKOUT_CLEARED = 'LOGIN_LOCKOUT_CLEARED';
    public const CREATE = 'CREATE';
    public const READ = 'READ';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';
    public const EXPORT = 'EXPORT';
    public const DOWNLOAD = 'DOWNLOAD';
    public const GRANT = 'GRANT';
    public const REVOKE = 'REVOKE';
    public const EXPIRE = 'EXPIRE';
    public const STATUS_CHANGE = 'STATUS_CHANGE';
    public const BAN = 'BAN';
    public const UNBAN = 'UNBAN';
    public const NOSHOW_RESET = 'NOSHOW_RESET';
    public const ASSIGN = 'ASSIGN';
    public const UNASSIGN = 'UNASSIGN';
    public const PUBLISH = 'PUBLISH';
    public const POSITION_ASSIGN = 'POSITION_ASSIGN';
    public const POSITION_UNASSIGN = 'POSITION_UNASSIGN';
    public const INVITE_CREATE = 'INVITE_CREATE';
    public const INVITE_REVOKE = 'INVITE_REVOKE';
    public const INVITE_REOPEN = 'INVITE_REOPEN';
    public const INVITE_USE = 'INVITE_USE';
    public const AVAILABILITY_REQUEST = 'AVAILABILITY_REQUEST';
    public const AUTO_MEMBERSHIP = 'AUTO_MEMBERSHIP';
    public const OVERRIDE = 'OVERRIDE';
    public const ACKNOWLEDGE = 'ACKNOWLEDGE';
    public const CLAIM = 'CLAIM';
    public const UNCLAIM = 'UNCLAIM';
    public const JOIN = 'JOIN';
    public const CLOSE = 'CLOSE';
    public const REOPEN = 'REOPEN';
    public const CANCEL = 'CANCEL';
    public const REFUSE = 'REFUSE';
    public const ACCEPT = 'ACCEPT';

    // Outcomes
    public const SUCCESS = 'SUCCESS';
    public const FAILURE = 'FAILURE';
}
