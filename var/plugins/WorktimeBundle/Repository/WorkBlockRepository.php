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

    /**
     * Blocks of the user whose [start, end) intersects [start, end), optionally
     * excluding one block id. Open blocks (end IS NULL) are treated as open-ended.
     *
     * @return WorkBlock[]
     */
    public function findOverlapping(User $user, \DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeId): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.start < :end')
            ->andWhere('b.end IS NULL OR b.end > :start')
            ->setParameter('user', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if (null !== $excludeId) {
            $qb->andWhere('b.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Open blocks (end IS NULL) of any user whose start is before the given
     * instant — used to auto-close forgotten punch-outs from previous days.
     *
     * @return WorkBlock[]
     */
    public function findOpenStartedBefore(\DateTimeInterface $before): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.end IS NULL')
            ->andWhere('b.start < :before')
            ->setParameter('before', $before)
            ->orderBy('b.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Review-flagged blocks (auto-closed) of the user starting within [from, to).
     *
     * @return WorkBlock[]
     */
    public function findNeedsReviewBetween(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.needsReview = true')
            ->andWhere('b.start >= :from')
            ->andWhere('b.start < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
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
