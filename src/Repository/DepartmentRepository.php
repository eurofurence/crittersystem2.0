<?php

namespace App\Repository;

use App\Entity\Department;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Department>
 */
class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    public function findOneByName(string $name): ?Department
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findOneBySlug(string $slug): ?Department
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Resolve a department by its public UUID (as exposed in URLs). Returns null for a
     * malformed uuid instead of letting the type conversion throw.
     */
    public function findOneByUuid(string $uuid): ?Department
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
    }

    /** @return Department[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
