<?php

namespace App\Repository;

use App\Entity\Certification;
use App\Entity\User;
use App\Entity\UserCertification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserCertification>
 */
class UserCertificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserCertification::class);
    }

    public function findOneByUserAndCertification(User $user, Certification $certification): ?UserCertification
    {
        return $this->findOneBy(['user' => $user, 'certification' => $certification]);
    }

    /** @return UserCertification[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('uc')
            ->join('uc.certification', 'c')->addSelect('c')
            ->andWhere('uc.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
