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
use KimaiPlugin\WorktimeBundle\Entity\BalanceCorrection;

/**
 * @extends ServiceEntityRepository<BalanceCorrection>
 */
class BalanceCorrectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BalanceCorrection::class);
    }

    /**
     * @return BalanceCorrection[]
     */
    public function findOvertimeForUserUpTo(User $user, \DateTimeInterface $date): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.account = :account')
            ->andWhere('c.date <= :date')
            ->setParameter('user', $user)
            ->setParameter('account', BalanceCorrection::ACCOUNT_OVERTIME)
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BalanceCorrection[]
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['date' => 'DESC']);
    }

    public function save(BalanceCorrection $correction): void
    {
        $em = $this->getEntityManager();
        $em->persist($correction);
        $em->flush();
    }
}
