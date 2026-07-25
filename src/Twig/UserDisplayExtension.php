<?php

namespace App\Twig;

use App\Entity\Department;
use App\Entity\User;
use App\Service\ContactMethodResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Renders a username with pronouns appended for normal UI contexts.
 * Search matching must not use this - it is presentation only.
 */
final class UserDisplayExtension extends AbstractExtension
{
    public function __construct(private readonly ContactMethodResolver $contacts)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('display_name', $this->displayName(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('real_name', $this->realName(...)),
        ];
    }

    public function displayName(User $user): string
    {
        $name = $user->getName();
        $pronoun = $user->getPersonalData()?->getPronoun();

        return $pronoun !== null && $pronoun !== '' ? sprintf('%s (%s)', $name, $pronoun) : $name;
    }

    /**
     * The subject's real name for the current viewer, respecting full_name_visible
     * consent, or null when they may not see it. Presentation only.
     */
    public function realName(User $user, ?Department $context = null): ?string
    {
        return $this->contacts->fullNameFor($user, $context);
    }
}
