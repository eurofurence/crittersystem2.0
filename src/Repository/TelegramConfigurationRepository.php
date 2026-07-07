<?php

namespace App\Repository;

use App\Entity\TelegramConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TelegramConfiguration> */
class TelegramConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramConfiguration::class);
    }

    public function current(): ?TelegramConfiguration
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
