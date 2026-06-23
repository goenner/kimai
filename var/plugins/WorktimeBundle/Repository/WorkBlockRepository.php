<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;

/**
 * @extends ServiceEntityRepository<WorkBlock>
 */
class WorkBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkBlock::class);
    }

    public function findOpenBlock(User $user): ?WorkBlock
    {
        return $this->findOneBy(['user' => $user, 'end' => null], ['start' => 'DESC']);
    }

    /**
     * @return WorkBlock[]
     */
    public function findForUserBetween(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.start >= :from')
            ->andWhere('b.start < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(WorkBlock $block): void
    {
        $em = $this->getEntityManager();
        $em->persist($block);
        $em->flush();
    }

    public function remove(WorkBlock $block): void
    {
        $em = $this->getEntityManager();
        $em->remove($block);
        $em->flush();
    }
}
