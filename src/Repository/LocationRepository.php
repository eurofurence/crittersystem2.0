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

    /** @return Location[] */
    public function findRootsOrdered(): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.parent IS NULL')
            ->orderBy('l.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByName(string $name): ?Location
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findOneByAlias(string $alias): ?Location
    {
        return $this->findOneBy(['alias' => $alias]);
    }

    /** @return Location[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
