<?php

namespace App\Repository;

use App\Entity\Certification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Certification>
 */
class CertificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Certification::class);
    }

    public function findOneByTitle(string $title): ?Certification
    {
        return $this->findOneBy(['title' => $title]);
    }

    /** @return Certification[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['title' => 'ASC']);
    }
}
