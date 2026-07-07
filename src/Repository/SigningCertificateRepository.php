<?php

namespace App\Repository;

use App\Entity\SigningCertificate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SigningCertificate>
 */
class SigningCertificateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SigningCertificate::class);
    }

    public function findActive(): ?SigningCertificate
    {
        return $this->findOneBy(['active' => true]);
    }
}
