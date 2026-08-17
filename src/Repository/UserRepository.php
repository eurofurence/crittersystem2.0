<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** Resolve a user by its public UUID (as exposed in URLs); null for a malformed uuid. */
    public function findOneByUuid(string $uuid): ?User
    {
        return \Symfony\Component\Uid\Uuid::isValid($uuid) ? $this->findOneBy(['uuid' => $uuid]) : null;
    }

    /**
     * Queue a re-run of onboarding for every user who has completed it. The reset
     * itself happens at each user's next sign-in (OnboardingResetSubscriber), so
     * signed-in sessions are not disturbed.
     *
     * Users who have not finished onboarding are skipped: they will see the wizard
     * anyway, and flagging them would misreport how many people this affects.
     *
     * @return int users flagged
     */
    public function requestOnboardingResetForAll(): int
    {
        return $this->createQueryBuilder('u')
            ->update()
            ->set('u.onboardingResetRequestedAt', ':now')
            ->andWhere('u.onboardingCompleted = true')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Hydrate the group assignments (and their groups) of many users in one query, so that a
     * membership list can call getRoles()/isStaff() per row without a lazy load each time.
     * The result is discarded: Doctrine keeps the hydrated collections on the managed entities.
     *
     * @param User[] $users
     */
    public function preloadGroupAssignments(array $users): void
    {
        if ($users === []) {
            return;
        }

        $this->createQueryBuilder('u')
            ->addSelect('ga', 'g')
            ->leftJoin('u.groupAssignments', 'ga')
            ->leftJoin('ga.group', 'g')
            ->andWhere('u IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();
    }

    /**
     * Partial, case-insensitive username search.
     *
     * @return User[]
     */
    public function searchByName(string $query, int $limit = 15): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.name) LIKE :like')
            ->setParameter('like', '%'.mb_strtolower($query).'%')
            ->orderBy('u.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Resolve a login by either username or email address.
     */
    public function findOneByUsernameOrEmail(string $identifier): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.name = :identifier OR u.email = :identifier')
            ->setParameter('identifier', $identifier)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByApiKey(string $apiKey): ?User
    {
        return $this->findOneBy(['apiKey' => $apiKey]);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Users belonging to any active group that grants the given role.
     *
     * @return User[]
     */
    public function findByGroupRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.groupAssignments', 'ga')
            ->join('ga.group', 'g')
            ->andWhere('g.role = :role')
            ->setParameter('role', $role)
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    /**
     * Manual accounts that never finished onboarding within the given cutoff.
     *
     * @return User[]
     */
    public function findStaleIncompleteOnboarding(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.onboardingCompleted = false')
            ->andWhere('u.accountSource = :manual')
            ->andWhere('u.lastLoginAt IS NULL')
            ->andWhere('u.createdAt < :cutoff')
            ->setParameter('manual', User::SOURCE_MANUAL)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Onboarded accounts that are not checked in yet. The state and the group memberships are
     * fetch-joined because the caller decides per user whether they count as staff, and the inverse
     * one-to-one state would otherwise cost a query for every row.
     *
     * @return User[]
     */
    public function findOnboardedNotCheckedIn(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.state', 'st')->addSelect('st')
            ->leftJoin('u.groupAssignments', 'ga')->addSelect('ga')
            ->leftJoin('ga.group', 'g')->addSelect('g')
            ->andWhere('u.onboardingCompleted = true')
            ->andWhere('st.id IS NULL OR st.arrived = false')
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Onboarded accounts holding no volunteer type at all.
     *
     * These cannot take a shift: eligibility asks for a confirmed membership of a type the shift
     * needs, and they have none. It happens when no volunteer type carried the role onboarding
     * matches on, so the assignment silently did nothing.
     *
     * Group assignments are fetch-joined because the caller asks each user whether they are staff,
     * to decide which default they should have had, and that would otherwise be a query per row.
     *
     * @return User[]
     */
    public function findOnboardedWithoutVolunteerType(string $query = '', int $limit = 50, int $offset = 0): array
    {
        $qb = $this->onboardedWithoutVolunteerTypeQuery($query)
            ->leftJoin('u.groupAssignments', 'ga')->addSelect('ga')
            ->leftJoin('ga.group', 'g')->addSelect('g')
            ->orderBy('u.name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $qb->getQuery()->getResult();
    }

    public function countOnboardedWithoutVolunteerType(string $query = ''): int
    {
        return (int) $this->onboardedWithoutVolunteerTypeQuery($query)
            ->select('COUNT(DISTINCT u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Matching is on the username only. The real name and the email are PII, and this screen is
     * reachable by anyone who may edit volunteer types, which is not the same set as those who may
     * read personal data.
     */
    private function onboardedWithoutVolunteerTypeQuery(string $query): QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.onboardingCompleted = true')
            ->andWhere('NOT EXISTS (SELECT uvt.id FROM App\Entity\UserVolunteerType uvt WHERE uvt.user = u)');

        if ('' !== trim($query)) {
            $qb->andWhere('LOWER(u.name) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($query)).'%');
        }

        return $qb;
    }

    /** @return User[] users who opted in to news emails (settings.emailNews) */
    public function findSubscribedToNews(): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.settings', 's')
            ->andWhere('s.emailNews = true')
            ->getQuery()
            ->getResult();
    }

    /**
     * Info-desk user lookup, dispatching on the shape of what was typed.
     *
     * An '@' means the operator already has a full address, so it is matched exactly and never
     * widened to a LIKE. All digits is a registration (badge) number, matched exactly, and never
     * the database id. Anything else is a name, and email is deliberately excluded from that branch
     * so a partial term can never be used to enumerate addresses.
     *
     * @return User[]
     */
    public function locate(string $query, int $limit = 25): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        if (str_contains($query, '@')) {
            return $this->createQueryBuilder('u')
                ->andWhere('LOWER(u.email) = :email')
                ->setParameter('email', mb_strtolower($query))
                ->getQuery()
                ->getResult();
        }

        if (ctype_digit($query)) {
            return $this->createQueryBuilder('u')
                ->join('u.personalData', 'p')
                ->andWhere('p.badgeNumber = :regnum')
                ->setParameter('regnum', (int) $query)
                ->orderBy('u.name', 'ASC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }

        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.name) LIKE :like')
            ->setParameter('like', '%'.mb_strtolower($query).'%')
            ->orderBy('u.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Search users by name, email, or numeric id (for the backstage user lookup).
     *
     * @return User[]
     */
    public function search(string $query, int $limit = 25): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        if (str_contains($query, '@')) {
            return $this->createQueryBuilder('u')
                ->andWhere('LOWER(u.email) = :email')
                ->setParameter('email', mb_strtolower($query))
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }

        if (ctype_digit($query)) {
            return $this->createQueryBuilder('u')
                ->join('u.personalData', 'p')
                ->andWhere('p.badgeNumber = :regnum')
                ->setParameter('regnum', (int) $query)
                ->orderBy('u.name', 'ASC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }

        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.name) LIKE :like')
            ->setParameter('like', '%'.mb_strtolower($query).'%')
            ->orderBy('u.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
