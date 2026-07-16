<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * Partial, case-insensitive username search — no email/id matching. Used by the
     * type-ahead user pickers, where only the username should be searchable.
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
     * Info-desk user lookup. Deliberately narrow to resist account mining: an email
     * only ever matches EXACTLY (never a substring), a digit string is treated as a
     * registration number and matched exactly, and the internal database id is never
     * searchable. Only plain names fall back to a substring match. Badge-scan tokens
     * are resolved by the caller (they need the digital-id service), not here.
     *
     * @return User[]
     */
    public function locate(string $query, int $limit = 25): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // Exact email — an '@' means the operator has a full address, so never widen it to a LIKE.
        if (str_contains($query, '@')) {
            return $this->createQueryBuilder('u')
                ->andWhere('LOWER(u.email) = :email')
                ->setParameter('email', mb_strtolower($query))
                ->getQuery()
                ->getResult();
        }

        // All digits — a registration (badge) number, matched exactly. Never the database id.
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

        // Anything else is treated as a name. Email is intentionally excluded here so a
        // partial term can never enumerate addresses.
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

        $qb = $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.name) LIKE :like OR LOWER(u.email) LIKE :like')
            ->setParameter('like', '%'.mb_strtolower($query).'%')
            ->orderBy('u.name', 'ASC')
            ->setMaxResults($limit);

        if (ctype_digit($query)) {
            $qb->orWhere('u.id = :id')->setParameter('id', (int) $query);
        }

        return $qb->getQuery()->getResult();
    }
}
