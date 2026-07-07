<?php

namespace App\Repository;

use App\Entity\TelegramLinkRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TelegramLinkRequest> */
class TelegramLinkRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramLinkRequest::class);
    }

    public function findOneByCode(string $code): ?TelegramLinkRequest
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findPendingForUser(\App\Entity\User $user): ?TelegramLinkRequest
    {
        return $this->findOneBy(['user' => $user, 'status' => TelegramLinkRequest::STATUS_PENDING], ['id' => 'DESC']);
    }
}
