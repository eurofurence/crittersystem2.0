<?php

namespace App\Repository;

use App\Entity\PrivacyNotice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivacyNotice>
 */
class PrivacyNoticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivacyNotice::class);
    }

    public function current(): ?PrivacyNotice
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }
}
