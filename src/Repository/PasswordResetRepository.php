<?php

namespace App\Repository;

use App\Entity\PasswordReset;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordReset>
 */
class PasswordResetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordReset::class);
    }

    public function findOneByToken(string $token): ?PasswordReset
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function deleteForUser(User $user): void
    {
        $this->createQueryBuilder('pr')
            ->delete()
            ->andWhere('pr.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
