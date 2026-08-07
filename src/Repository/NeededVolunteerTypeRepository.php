<?php

namespace App\Repository;

use App\Entity\NeededVolunteerType;
use App\Entity\Shift;
use App\Entity\VolunteerType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NeededVolunteerType>
 */
class NeededVolunteerTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NeededVolunteerType::class);
    }

    /**
     * Effective staffing needs for a shift using the three-tier priority:
     * a shift-level requirement overrides a location- or
     * shift-task-level one for the same volunteer type.
     *
     * @return array<int, NeededVolunteerType> keyed by volunteer type id
     */
    public function findEffectiveForShift(Shift $shift): array
    {
        /** @var NeededVolunteerType[] $candidates */
        $candidates = $this->createQueryBuilder('n')
            ->andWhere('n.shift = :shift OR n.location = :location OR n.shiftTask = :shiftTask')
            ->setParameter('shift', $shift)
            ->setParameter('location', $shift->getLocation())
            ->setParameter('shiftTask', $shift->getShiftTask())
            ->getQuery()
            ->getResult();

        $effective = [];
        foreach ($candidates as $needed) {
            $typeId = $needed->getVolunteerType()->getId();
            $existing = $effective[$typeId] ?? null;
            if ($existing === null || $this->tier($needed) < $this->tier($existing)) {
                $effective[$typeId] = $needed;
            }
        }

        return $effective;
    }

    /**
     * The same three-tier resolution for a whole list of shifts, in one query.
     *
     * A shift list that asks per shift spends a query per row before it has read a single volunteer
     * type, which is what made the staff application screen slow enough to be reported as broken.
     *
     * @param Shift[] $shifts
     *
     * @return array<int, array<int, NeededVolunteerType>> shift id => volunteer type id => need
     */
    public function findEffectiveForShifts(array $shifts): array
    {
        $shifts = array_values(array_filter($shifts, static fn (Shift $s): bool => $s->getId() !== null));
        if ($shifts === []) {
            return [];
        }

        $locations = [];
        $tasks = [];
        foreach ($shifts as $shift) {
            if (($location = $shift->getLocation()) !== null) {
                $locations[$location->getId()] = $location;
            }
            if (($task = $shift->getShiftTask()) !== null) {
                $tasks[$task->getId()] = $task;
            }
        }

        $qb = $this->createQueryBuilder('n');
        $conditions = ['n.shift IN (:shifts)'];
        $qb->setParameter('shifts', $shifts);
        if ($locations !== []) {
            $conditions[] = 'n.location IN (:locations)';
            $qb->setParameter('locations', array_values($locations));
        }
        if ($tasks !== []) {
            $conditions[] = 'n.shiftTask IN (:tasks)';
            $qb->setParameter('tasks', array_values($tasks));
        }

        /** @var NeededVolunteerType[] $candidates */
        $candidates = $qb->andWhere(implode(' OR ', $conditions))->getQuery()->getResult();

        $byShift = [];
        $byLocation = [];
        $byTask = [];
        foreach ($candidates as $need) {
            if (($shift = $need->getShift()) !== null) {
                $byShift[$shift->getId()][] = $need;
            } elseif (($location = $need->getLocation()) !== null) {
                $byLocation[$location->getId()][] = $need;
            } elseif (($task = $need->getShiftTask()) !== null) {
                $byTask[$task->getId()][] = $need;
            }
        }

        $effective = [];
        foreach ($shifts as $shift) {
            $applicable = array_merge(
                $byShift[$shift->getId()] ?? [],
                $byLocation[$shift->getLocation()?->getId()] ?? [],
                $byTask[$shift->getShiftTask()?->getId()] ?? [],
            );

            $resolved = [];
            foreach ($applicable as $need) {
                $typeId = $need->getVolunteerType()->getId();
                $existing = $resolved[$typeId] ?? null;
                if ($existing === null || $this->tier($need) < $this->tier($existing)) {
                    $resolved[$typeId] = $need;
                }
            }
            $effective[$shift->getId()] = $resolved;
        }

        return $effective;
    }

    /** Lower tier number = higher priority: shift (0) < location (1) < shift type (2). */
    private function tier(NeededVolunteerType $needed): int
    {
        if ($needed->getShift() !== null) {
            return 0;
        }

        return $needed->getLocation() !== null ? 1 : 2;
    }

    public function findOneForShiftAndType(Shift $shift, VolunteerType $type): ?NeededVolunteerType
    {
        return $this->findOneBy(['shift' => $shift, 'volunteerType' => $type]);
    }
}
