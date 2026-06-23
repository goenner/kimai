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
use KimaiPlugin\WorktimeBundle\Entity\Absence;

/**
 * @extends ServiceEntityRepository<Absence>
 */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    /**
     * @return Absence[]
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['startDate' => 'DESC']);
    }

    /**
     * @return Absence[]
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => Absence::STATUS_OPEN], ['startDate' => 'ASC']);
    }

    /**
     * Approved absences for the user that start within the given calendar year.
     *
     * @return Absence[]
     */
    public function findApprovedForUserInYear(User $user, int $year): array
    {
        $from = new \DateTimeImmutable(\sprintf('%04d-01-01', $year));
        $to = new \DateTimeImmutable(\sprintf('%04d-12-31', $year));

        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('a.status = :status')
            ->andWhere('a.startDate >= :from')
            ->andWhere('a.startDate <= :to')
            ->setParameter('user', $user)
            ->setParameter('status', Absence::STATUS_APPROVED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Absence $absence): void
    {
        $em = $this->getEntityManager();
        $em->persist($absence);
        $em->flush();
    }

    public function remove(Absence $absence): void
    {
        $em = $this->getEntityManager();
        $em->remove($absence);
        $em->flush();
    }
}
