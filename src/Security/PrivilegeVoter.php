<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants fine-grained access based on a user's effective privileges.
 *
 * Enables checks like `is_granted('admin_user')` in controllers and
 * `{{ is_granted('news') }}` in Twig. It only votes on attributes that are
 * known privilege names (see PrivilegeCatalog); any other attribute
 * (ROLE_*, IS_AUTHENTICATED_*, custom voters) is left to other voters.
 *
 * The `admin` privilege means "full administrative access", so it
 * satisfies every privilege check rather than only the literal `admin` attribute.
 *
 * @extends Voter<string, mixed>
 */
class PrivilegeVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return PrivilegeCatalog::isPrivilege($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $user->hasPrivilege('admin') || $user->hasPrivilege($attribute);
    }
}
