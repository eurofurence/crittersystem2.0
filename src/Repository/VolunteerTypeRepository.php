<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\VolunteerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<VolunteerType>
 */
class VolunteerTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerType::class);
    }

    public function findOneByUuid(string $uuid): ?VolunteerType
    {
        return Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
    }

    public function findOneByName(string $name): ?VolunteerType
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * The base type the system assigns by itself.
     *
     * Match on the role rather than the name: an event renames these to suit itself - Critter for
     * Volunteer here - and a lookup keyed on the label stops finding them the moment it does.
     */
    public function findOneByRole(string $role): ?VolunteerType
    {
        return $this->findOneBy(['role' => $role]);
    }

    public function findDefaultFor(User $user): ?VolunteerType
    {
        return $this->findOneByRole($user->isStaff() ? VolunteerType::ROLE_STAFF : VolunteerType::ROLE_VOLUNTEER);
    }

    /** @return VolunteerType[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * The same list with each type's required certifications hydrated, for the compliance report,
     * which reads them on every row.
     *
     * @return VolunteerType[]
     */
    public function findAllOrderedWithCertifications(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.certifications', 'c')
            ->addSelect('c')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The same list, with each type's departments already hydrated. Callers that scope types to a
     * department read `getDepartments()` on every row, which is one query per type without the join.
     *
     * @return VolunteerType[]
     */
    public function findAllOrderedWithDepartments(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.departments', 'd')
            ->addSelect('d')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
