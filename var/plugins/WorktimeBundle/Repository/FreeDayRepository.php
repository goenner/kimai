<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\WorktimeBundle\Entity\FreeDay;

/**
 * @extends ServiceEntityRepository<FreeDay>
 */
class FreeDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FreeDay::class);
    }

    /**
     * @return FreeDay[]
     */
    public function findBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.day >= :from')
            ->andWhere('f.day <= :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('f.day', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(FreeDay $day): void
    {
        $this->getEntityManager()->persist($day);
        $this->getEntityManager()->flush();
    }

    public function delete(FreeDay $day): void
    {
        $this->getEntityManager()->remove($day);
        $this->getEntityManager()->flush();
    }
}
