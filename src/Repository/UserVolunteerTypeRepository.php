<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserVolunteerType;
use App\Entity\VolunteerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserVolunteerType>
 */
class UserVolunteerTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserVolunteerType::class);
    }

    public function findOneByUserAndType(User $user, VolunteerType $type): ?UserVolunteerType
    {
        return $this->findOneBy(['user' => $user, 'volunteerType' => $type]);
    }

    /** @return UserVolunteerType[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.volunteerType', 't')
            ->addSelect('t')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return UserVolunteerType[] memberships of a type, e.g. for supporter management */
    public function findByVolunteerType(VolunteerType $type): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.user', 'u')
            ->addSelect('u')
            ->andWhere('m.volunteerType = :type')
            ->setParameter('type', $type)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Confirmed memberships of the given types, keyed by volunteer type id, users joined.
     *
     * One query for the whole set: the compliance report walks every role that requires a
     * certification, and asking per role is a query per role before a single member is read.
     *
     * @param VolunteerType[] $types
     *
     * @return array<int, list<UserVolunteerType>>
     */
    public function findConfirmedByTypes(array $types): array
    {
        if ($types === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->join('m.user', 'u')->addSelect('u')
            ->andWhere('m.volunteerType IN (:types)')
            ->andWhere('m.confirmedBy IS NOT NULL')
            ->setParameter('types', $types)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        $byType = [];
        foreach ($rows as $membership) {
            $byType[$membership->getVolunteerType()->getId()][] = $membership;
        }

        return $byType;
    }

    public function isConfirmedMember(User $user, VolunteerType $type): bool
    {
        $membership = $this->findOneByUserAndType($user, $type);

        return $membership !== null && $membership->isConfirmed();
    }

    /**
     * Pending (unconfirmed) membership requests, oldest first. When $types is
     * given, restrict to those volunteer types (e.g. the ones a supporter manages).
     *
     * @param VolunteerType[]|null $types
     *
     * @return UserVolunteerType[]
     */
    public function findPending(?array $types = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.user', 'u')->addSelect('u')
            ->join('m.volunteerType', 't')->addSelect('t')
            ->andWhere('m.confirmedBy IS NULL')
            ->orderBy('m.createdAt', 'ASC');

        if ($types !== null) {
            if ($types === []) {
                return [];
            }
            $qb->andWhere('m.volunteerType IN (:types)')->setParameter('types', $types);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return VolunteerType[] types the user is a supporter of */
    public function findSupportedTypes(User $user): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('t')
            ->join('m.volunteerType', 't')
            ->andWhere('m.user = :user')
            ->andWhere('m.supporter = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
