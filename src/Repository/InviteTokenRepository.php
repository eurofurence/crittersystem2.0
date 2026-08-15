<?php

namespace App\Repository;

use App\Entity\InviteToken;
use App\Entity\User;
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

    public function findOneByUser(User $user): ?InviteToken
    {
        return $this->findOneBy(['user' => $user]);
    }
}
