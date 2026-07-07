<?php

namespace App\Repository;

use App\Entity\InviteToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InviteToken>
 */
class InviteTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InviteToken::class);
    }

    public function findOneByToken(string $token): ?InviteToken
    {
        return $this->findOneBy(['token' => $token]);
    }
}
