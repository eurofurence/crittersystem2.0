<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** @see \App\Controller\SsoController */
class RegistrationApiData
{
    #[Assert\Length(max: 512)]
    #[Assert\Url(protocols: ['http', 'https'])]
    public ?string $apiUrl = null;
}
