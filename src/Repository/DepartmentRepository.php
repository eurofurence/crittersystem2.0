<?php

namespace App\Repository;

use App\Entity\Department;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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

    /** @return Department[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
