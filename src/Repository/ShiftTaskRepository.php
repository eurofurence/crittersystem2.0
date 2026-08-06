<?php

namespace App\Repository;

use App\Entity\ShiftTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ShiftTask>
 */
class ShiftTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShiftTask::class);
    }

    public function findOneByUuid(string $uuid): ?ShiftTask
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
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
