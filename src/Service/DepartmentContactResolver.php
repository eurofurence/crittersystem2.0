<?php

namespace App\Service;

use App\Entity\Department;
use App\Entity\User;
use App\Entity\UserGroupAssignment;
use App\Security\PrivilegeCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who to ask about a department's shifts.
 *
 * {@see \App\Security\PrivilegeScopeResolver} answers "which departments does this user hold a
 * privilege in". This answers the inverse - "who holds it here" - which nothing else in the
 * application could do, and which the shift dossier needs so an operator can route a question about
 * a badly described shift to a person rather than to nobody.
 */
final class DepartmentContactResolver
{
    /**
     * Holding either privilege makes somebody answerable for a department's shifts: one owns the
     * shifts, the other owns the department.
     */
    private const PRIVILEGES = ['shift:manage', 'department:manage'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * The people answerable for this department, by username.
     *
     * A grant scoped to the department wins outright. Event-wide holders are answerable too, but
     * listing them alongside is useless at a desk: they are answerable for every department at once,
     * so they are offered only when the department has nobody of its own.
     *
     * Administrators are excluded. `global:admin` and `ROLE_ADMIN` satisfy every check, so including
     * them names the entire admin team on every shift in the event and buries whoever actually
     * planned it.
     *
     * @return User[]
     */
    public function managersOf(Department $department): array
    {
        $administrators = $this->administratorIds();
        $scoped = [];
        $eventWide = [];

        foreach ($this->candidates($department) as $assignment) {
            $user = $assignment->getUser();
            if (\in_array($user->getId(), $administrators, true)) {
                continue;
            }

            if ($assignment->getDepartment() !== null) {
                $scoped[$user->getId()] = $user;
            } else {
                $eventWide[$user->getId()] = $user;
            }
        }

        $chosen = $scoped !== [] ? $scoped : $eventWide;
        usort($chosen, static fn (User $a, User $b): int => strcasecmp($a->getName(), $b->getName()));

        return $chosen;
    }

    /**
     * Active assignments whose group carries one of the privileges, scoped to this department or to
     * nothing at all. A null scope is deliberately event-wide, so excluding it in SQL would drop the
     * fallback before it could be considered.
     *
     * The user's inverse one-to-one relations are joined in: Doctrine cannot proxy them and resolves
     * each with its own query the moment a User is hydrated, whether or not anything reads it.
     *
     * @return UserGroupAssignment[]
     */
    private function candidates(Department $department): array
    {
        return $this->em->createQueryBuilder()
            ->select('a')
            ->from(UserGroupAssignment::class, 'a')
            ->join('a.group', 'g')
            ->join('g.privileges', 'p')
            ->join('a.user', 'u')->addSelect('u')
            ->leftJoin('u.personalData', 'pd')->addSelect('pd')
            ->leftJoin('u.contact', 'c')->addSelect('c')
            ->leftJoin('u.settings', 's')->addSelect('s')
            ->leftJoin('u.state', 'st')->addSelect('st')
            ->leftJoin('u.consent', 'cons')->addSelect('cons')
            ->andWhere('p.name IN (:privileges)')
            ->andWhere('a.department = :department OR a.department IS NULL')
            ->andWhere('a.expiresAt IS NULL OR a.expiresAt > :now')
            ->setParameter('privileges', self::PRIVILEGES)
            ->setParameter('department', $department)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Everyone with event-wide administrative access, whether by role or by the super-privilege.
     *
     * Asked of the database rather than of each candidate's own groups: a hydrated User answers from
     * its assignment collection, which costs a query per person, and answers wrongly for one that has
     * not been loaded from the database at all.
     *
     * @return int[]
     */
    private function administratorIds(): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT u.id')
            ->from(UserGroupAssignment::class, 'a')
            ->join('a.user', 'u')
            ->join('a.group', 'g')
            ->leftJoin('g.privileges', 'p')
            ->andWhere('(g.role = :adminRole OR p.name = :super)')
            ->andWhere('(a.expiresAt IS NULL OR a.expiresAt > :now)')
            ->setParameter('adminRole', 'ROLE_ADMIN')
            ->setParameter('super', PrivilegeCatalog::SUPER)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getScalarResult();

        return array_map(intval(...), array_column($rows, 'id'));
    }
}
