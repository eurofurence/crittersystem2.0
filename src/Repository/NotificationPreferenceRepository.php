<?php

namespace App\Repository;

use App\Entity\NotificationPreference;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationPreference>
 */
class NotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationPreference::class);
    }

    public function findOneForUserCategory(User $user, string $category): ?NotificationPreference
    {
        return $this->findOneBy(['user' => $user, 'category' => $category]);
    }

    /**
     * @return array<string, NotificationPreference> keyed by category
     */
    public function mapForUser(User $user): array
    {
        $map = [];
        foreach ($this->findBy(['user' => $user]) as $preference) {
            $map[$preference->getCategory()] = $preference;
        }

        return $map;
    }
}
