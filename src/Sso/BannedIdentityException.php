<?php

declare(strict_types=1);

namespace App\Sso;

/** Thrown when an SSO login is refused because the identity is banned. */
final class BannedIdentityException extends \RuntimeException
{
}
