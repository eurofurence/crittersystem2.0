<?php

namespace App\Form\Model;

use App\Entity\Group;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/** Plain input for inviting a new user. */
final class UserInviteData
{
    public string $username = '';
    public string $email = '';
    public ?string $firstName = null;
    public ?string $lastName = null;

    /** @var Collection<int, Group> */
    public Collection $groups;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }
}
