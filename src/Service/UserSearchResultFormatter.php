<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shapes a list of users into the JSON the `user-select` type-ahead widget consumes.
 * The identifier is the public UUID, never the internal primary key.
 */
final class UserSearchResultFormatter
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param iterable<User> $users
     *
     * @return array{results: list<array{id: string, name: string, staff: bool, avatar: ?string}>}
     */
    public function results(iterable $users): array
    {
        $results = [];
        foreach ($users as $user) {
            $results[] = [
                'id' => (string) $user->getUuid(),
                'name' => $user->getName(),
                'staff' => $user->isStaff(),
                'avatar' => $user->getPersonalData()?->getAvatarPath() !== null
                    ? $this->urls->generate('app_media_avatar', ['id' => $user->getUuid()])
                    : null,
            ];
        }

        return ['results' => $results];
    }
}
