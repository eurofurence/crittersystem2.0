<?php

declare(strict_types=1);

namespace App\Sso;

/** Normalised identity claims from the OIDC provider. */
final readonly class SsoClaims
{
    /** @param string[] $groups structured SSO group ids (reported as "roles") */
    public function __construct(
        public string $sub,
        public string $email,
        public ?string $preferredUsername,
        public ?string $name,
        public array $groups,
        public ?string $avatar = null,
    ) {
    }

    /** @param array<string, mixed> $data raw userinfo/id_token claims */
    public static function fromArray(array $data): self
    {
        $groups = $data['groups'] ?? $data['roles'] ?? [];

        $preferredUsername = match (true) {
            isset($data['preferred_username']) => (string) $data['preferred_username'],
            isset($data['username']) => (string) $data['username'],
            isset($data['name']) => (string) $data['name'],
            default => null,
        };

        $name = match (true) {
            isset($data['first_name']) => trim(
                (string) $data['first_name'] . ' ' . (string) ($data['last_name'] ?? '')
            ),
            isset($data['name']) => (string) $data['name'],
            default => null,
        };

        $avatar = match (true) {
            isset($data['avatar']) => (string) $data['avatar'],
            isset($data['picture']) => (string) $data['picture'],
            default => null,
        };

        return new self(
            sub: (string) ($data['sub'] ?? ''),
            email: (string) ($data['email'] ?? ''),
            preferredUsername: $preferredUsername,
            name: $name,
            groups: array_values(array_map('strval', (array) $groups)),
            avatar: $avatar,
        );
        // Works well with KEYCLOAK
        // return new self(
        //     sub: (string) ($data['sub'] ?? ''),
        //     email: (string) ($data['email'] ?? ''),
        //     preferredUsername: isset($data['preferred_username']) ? (string) $data['preferred_username'] : null,
        //     name: isset($data['name']) ? (string) $data['name'] : null,
        //     groups: array_values(array_map('strval', (array) $groups)),
        // );
    }
}
