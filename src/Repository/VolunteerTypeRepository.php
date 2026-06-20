<?php

namespace App\Repository;

use App\Entity\VolunteerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerType>
 */
class VolunteerTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerType::class);
    }

    public function findOneByName(string $name): ?VolunteerType
    {
        return $this->findOneBy(['name' => $name]);
    }

    /** @return VolunteerType[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
