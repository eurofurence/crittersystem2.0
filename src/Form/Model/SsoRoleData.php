<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** @see \App\Controller\Admin\SsoRoleController */
class SsoRoleData
{
    #[Assert\Length(max: 64)]
    public ?string $departmentManagerRole = null;

    #[Assert\Length(max: 64)]
    public ?string $shiftManagerRole = null;

    #[Assert\Length(max: 64)]
    public ?string $globalAdminRole = null;

    #[Assert\Length(max: 64)]
    public ?string $subAdminRole = null;
}
