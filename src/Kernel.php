<?php

namespace App;

use App\Doctrine\EncryptedStringType;
use App\Service\SecretCipher;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        // DBAL builds custom types without the service container, so the
        // encrypted column type is handed its cipher once the container exists.
        EncryptedStringType::setCipher($this->getContainer()->get(SecretCipher::class));
    }
}
