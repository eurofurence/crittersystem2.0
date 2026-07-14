<?php

namespace App\Repository;

use App\Entity\ShiftTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShiftTask>
 */
class ShiftTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShiftTask::class);
    }

    public function findOneByName(string $name): ?ShiftTask
    {
        return $this->findOneBy(['name' => $name]);
    }

    /** @return ShiftTask[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
