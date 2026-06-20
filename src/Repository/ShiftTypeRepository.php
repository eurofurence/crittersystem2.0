<?php

namespace App\Repository;

use App\Entity\ShiftType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShiftType>
 */
class ShiftTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShiftType::class);
    }

    public function findOneByName(string $name): ?ShiftType
    {
        return $this->findOneBy(['name' => $name]);
    }

    /** @return ShiftType[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
