<?php

namespace App\Twig;

use App\Entity\User;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Renders a username with pronouns appended for normal UI contexts.
 * Search matching must not use this — it is presentation only.
 */
final class UserDisplayExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('display_name', $this->displayName(...)),
        ];
    }

    public function displayName(User $user): string
    {
        $name = $user->getName();
        $pronoun = $user->getPersonalData()?->getPronoun();

        return $pronoun !== null && $pronoun !== '' ? sprintf('%s (%s)', $name, $pronoun) : $name;
    }
}
