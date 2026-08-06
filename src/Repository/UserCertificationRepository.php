<?php

namespace App\Repository;

use App\Entity\Certification;
use App\Entity\User;
use App\Entity\UserCertification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserCertification>
 */
class UserCertificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserCertification::class);
    }

    public function findOneByUserAndCertification(User $user, Certification $certification): ?UserCertification
    {
        return $this->findOneBy(['user' => $user, 'certification' => $certification]);
    }

    /**
     * Every record for one certification, with the holder joined.
     *
     * One query: the holder page reads each row's user name, which is a query per row without this.
     *
     * @return UserCertification[]
     */
    public function findForCertification(Certification $certification): array
    {
        return $this->createQueryBuilder('uc')
            ->join('uc.user', 'u')->addSelect('u')
            ->andWhere('uc.certification = :certification')
            ->setParameter('certification', $certification)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every record for any of the given certifications, holders joined.
     *
     * @param Certification[] $certifications
     *
     * @return UserCertification[]
     */
    public function findForCertifications(array $certifications): array
    {
        if ($certifications === []) {
            return [];
        }

        return $this->createQueryBuilder('uc')
            ->join('uc.user', 'u')->addSelect('u')
            ->join('uc.certification', 'c')->addSelect('c')
            ->andWhere('uc.certification IN (:certifications)')
            ->setParameter('certifications', $certifications)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every pending application across all certifications, oldest first: the queue is worked through
     * from the top, so somebody who applied on day one is not left behind requests made this morning.
     *
     * @return UserCertification[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('uc')
            ->join('uc.user', 'u')->addSelect('u')
            ->join('uc.certification', 'c')->addSelect('c')
            ->andWhere('uc.status = :pending')
            ->setParameter('pending', UserCertification::STATUS_PENDING)
            ->orderBy('uc.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Granted records whose expiry has already passed but which still say they are held.
     *
     * @return UserCertification[]
     */
    public function findLapsed(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('uc')
            ->join('uc.user', 'u')->addSelect('u')
            ->join('uc.certification', 'c')->addSelect('c')
            ->andWhere('uc.status IN (:granted)')
            ->andWhere('uc.dateExpires IS NOT NULL')
            ->andWhere('uc.dateExpires < :now')
            ->setParameter('granted', [UserCertification::STATUS_APPROVED, UserCertification::STATUS_SELF_CONFIRMED])
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Held records running out within the window that nobody has been warned about yet.
     *
     * The reminder timestamp is compared against the expiry rather than against a fixed age: a
     * record renewed after its warning gets a fresh expiry and its timestamp cleared, so the next
     * period warns again.
     *
     * @return UserCertification[]
     */
    public function findExpiringUnwarned(\DateTimeImmutable $now, \DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('uc')
            ->join('uc.user', 'u')->addSelect('u')
            ->join('uc.certification', 'c')->addSelect('c')
            ->andWhere('uc.status IN (:granted)')
            ->andWhere('uc.dateExpires IS NOT NULL')
            ->andWhere('uc.dateExpires > :now')
            ->andWhere('uc.dateExpires <= :until')
            ->andWhere('uc.expiryRemindedAt IS NULL')
            ->setParameter('granted', [UserCertification::STATUS_APPROVED, UserCertification::STATUS_SELF_CONFIRMED])
            ->setParameter('now', $now)
            ->setParameter('until', $until)
            ->orderBy('uc.dateExpires', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Headline counts for the certification overview, in one query rather than five.
     *
     * `held` counts what is valid right now, so an approved record whose expiry has passed lands in
     * `expired` instead: the number a manager reads as "people qualified today" has to mean that.
     *
     * @return array{applications: int, held: int, expiring: int, expired: int, revoked: int}
     */
    public function statistics(int $expiringWithinDays = 30): array
    {
        $now = new \DateTimeImmutable();
        $soon = $now->modify(\sprintf('+%d days', $expiringWithinDays));

        $rows = $this->createQueryBuilder('uc')
            ->select('uc.status AS status, uc.dateExpires AS expires')
            ->getQuery()
            ->getArrayResult();

        $counts = ['applications' => 0, 'held' => 0, 'expiring' => 0, 'expired' => 0, 'revoked' => 0];
        foreach ($rows as $row) {
            $granted = \in_array($row['status'], [UserCertification::STATUS_APPROVED, UserCertification::STATUS_SELF_CONFIRMED], true);
            $lapsed = $row['expires'] !== null && $row['expires'] < $now;

            match (true) {
                $row['status'] === UserCertification::STATUS_PENDING => ++$counts['applications'],
                $row['status'] === UserCertification::STATUS_REVOKED => ++$counts['revoked'],
                $granted && $lapsed => ++$counts['expired'],
                $granted => ++$counts['held'],
                default => null,
            };

            if ($granted && !$lapsed && $row['expires'] !== null && $row['expires'] <= $soon) {
                ++$counts['expiring'];
            }
        }

        return $counts;
    }

    /** @return UserCertification[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('uc')
            ->join('uc.certification', 'c')->addSelect('c')
            ->andWhere('uc.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
