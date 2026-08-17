<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;

/**
 * Produces a unique username, appending a random numeric suffix on collision
 * (e.g. "user_23"). Used for SSO-provisioned accounts where the source system
 * may issue duplicate usernames, and to keep manual creation collision-free.
 */
final class UsernameGenerator
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function unique(string $desired): string
    {
        $desired = trim($desired) !== '' ? trim($desired) : 'user';
        if ($this->users->findOneBy(['name' => $desired]) === null) {
            return $desired;
        }

        do {
            $candidate = $this->truncate($desired).'_'.random_int(10, 99);
        } while ($this->users->findOneBy(['name' => $candidate]) !== null);

        return $candidate;
    }

    /** Leaves room for the "_NN" suffix within the 24-character username limit. */
    private function truncate(string $name): string
    {
        return mb_substr($name, 0, 21);
    }
}
