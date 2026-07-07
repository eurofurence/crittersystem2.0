<?php

namespace App\Repository;

use App\Entity\ConsentText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConsentText>
 */
class ConsentTextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsentText::class);
    }

    public function findOneByLocale(string $locale): ?ConsentText
    {
        return $this->findOneBy(['locale' => $locale]);
    }

    /**
     * The consent text for the requested locale, falling back to en_US, then to
     * any available row.
     */
    public function resolve(string $locale): ?ConsentText
    {
        return $this->findOneByLocale($locale)
            ?? $this->findOneByLocale('en_US')
            ?? $this->findOneBy([]);
    }
}
