<?php

namespace App\Repository;

use App\Entity\Department;
use App\Entity\DelegatedManagerRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DelegatedManagerRequest>
 */
class DelegatedManagerRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DelegatedManagerRequest::class);
    }

    /** @return DelegatedManagerRequest[] */
    public function findPendingByDepartment(Department $department): array
    {
        return $this->findBy(
            ['department' => $department, 'status' => DelegatedManagerRequest::STATUS_PENDING],
            ['createdAt' => 'DESC'],
        );
    }

    public function findPending(Department $department, User $subject): ?DelegatedManagerRequest
    {
        return $this->findOneBy([
            'department' => $department,
            'subject' => $subject,
            'status' => DelegatedManagerRequest::STATUS_PENDING,
        ]);
    }
}
