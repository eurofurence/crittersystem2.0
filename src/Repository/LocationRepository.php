<?php

namespace App\Repository;

use App\Entity\Location;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Location>
 */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    public function findOneByUuid(string $uuid): ?Location
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
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

    /**
     * Every location ordered so each child follows its own parent, for pickers and admin lists that
     * must show where a child sits: sorting by bare name scatters "Hall 5" and "Check-in 1" among
     * unrelated roots, which is what makes a child unidentifiable in a dropdown.
     *
     * The ancestors are selected with the rows because the caller renders {@see Location::fullName()},
     * which walks the parent chain: without the joins a 72-location picker costs one extra query per
     * distinct ancestor. Two joins cover the whole tree - nesting is capped at root plus two levels.
     *
     * Sorted on the composed path so a child sorts directly under its parent, and naturally so that
     * "Hall 5" precedes "Hall 10" rather than following it.
     *
     * @return Location[]
     */
    public function findAllOrderedByPath(): array
    {
        $locations = $this->createQueryBuilder('l')
            ->leftJoin('l.parent', 'p')->addSelect('p')
            ->leftJoin('p.parent', 'gp')->addSelect('gp')
            ->getQuery()
            ->getResult();

        usort($locations, static fn (Location $a, Location $b): int => strnatcasecmp($a->fullName(), $b->fullName()));

        return $locations;
    }
}
