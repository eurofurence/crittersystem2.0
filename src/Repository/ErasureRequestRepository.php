<?php

namespace App\Repository;

use App\Entity\ErasureRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ErasureRequest>
 */
class ErasureRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ErasureRequest::class);
    }

    public function findOneByToken(string $token): ?ErasureRequest
    {
        return $this->findOneBy(['token' => $token]);
    }
}
