<?php

namespace App\Repository;

use App\Entity\Certification;
use App\Entity\CertificationToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CertificationToken>
 */
class CertificationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CertificationToken::class);
    }

    public function findActiveForCertification(Certification $certification): ?CertificationToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.certification = :c')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('c', $certification)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('t.expiresAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActive(string $token): ?CertificationToken
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.token = :token')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteExpired(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->delete()
            ->andWhere('t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
