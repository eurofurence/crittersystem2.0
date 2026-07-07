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
    ) {
    }

    /** @param array<string, mixed> $data raw userinfo/id_token claims */
    public static function fromArray(array $data): self
    {
        $groups = $data['groups'] ?? $data['roles'] ?? [];

        return new self(
            sub: (string) ($data['sub'] ?? ''),
            email: (string) ($data['email'] ?? ''),
            preferredUsername: isset($data['preferred_username']) ? (string) $data['preferred_username'] : null,
            name: isset($data['name']) ? (string) $data['name'] : null,
            groups: array_values(array_map('strval', (array) $groups)),
        );
    }
}
