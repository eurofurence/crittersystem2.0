<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Masks personally identifiable information (email, phone, Telegram id, IP, SSO
 * ids) from anyone without the user:pii:view permission. Only the site admin may
 * see the raw values; sub-admins and everyone else get a masked rendering.
 */
final class PiiMasker
{
    public function __construct(private readonly Security $security)
    {
    }

    public function canSeePii(): bool
    {
        return $this->security->isGranted('user:pii:view');
    }

    public function email(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($this->canSeePii()) {
            return $value;
        }
        [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');

        return $this->maskKeepingEnds($local).'@'.($domain !== '' ? $this->maskKeepingEnds($domain) : '');
    }

    public function generic(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->canSeePii() ? $value : '••••••';
    }

    private function maskKeepingEnds(string $value): string
    {
        $len = mb_strlen($value);
        if ($len <= 2) {
            return str_repeat('•', max(2, $len));
        }

        return mb_substr($value, 0, 1).str_repeat('•', max(2, $len - 2)).mb_substr($value, -1);
    }
}
