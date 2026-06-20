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
