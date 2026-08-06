<?php

namespace App\Repository;

use App\Entity\Group;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Group>
 */
class GroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    public function findOneByUuid(string $uuid): ?Group
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
    }

    public function findOneByName(string $name): ?Group
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function findOneBySlug(string $slug): ?Group
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
