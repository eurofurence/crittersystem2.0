<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * The slice of the development database that must survive an import, read
 * before the schema is dropped. See {@see DevStatePreserver}.
 */
final readonly class DevStateSnapshot
{
    /**
     * @param array{name: string, email: string, password: string}|null $admin
     * @param array<string, ?string>                                    $eventConfig raw JSON values by key
     * @param array<string, mixed>|null                                 $telegram    the telegram_configuration row
     */
    public function __construct(
        public ?array $admin,
        public array $eventConfig,
        public ?array $telegram,
    ) {
    }
}
