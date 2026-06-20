<?php

namespace App\Repository;

use App\Entity\EventConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventConfig>
 */
class EventConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventConfig::class);
    }

    public function findOneByKey(string $key): ?EventConfig
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * All settings as a flat key => value map.
     *
     * @return array<string, mixed>
     */
    public function findAllAsMap(): array
    {
        $map = [];
        foreach ($this->findAll() as $config) {
            $map[$config->getKey()] = $config->getValue();
        }

        return $map;
    }
}
