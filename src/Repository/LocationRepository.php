<?php

namespace App\Repository;

use App\Entity\Location;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Location>
 */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    public function findOneByName(string $name): ?Location
    {
        return $this->findOneBy(['name' => $name]);
    }

    /** @return Location[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
