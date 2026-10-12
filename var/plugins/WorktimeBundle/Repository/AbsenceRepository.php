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
     * DATE-Spalten als 'Y-m-d' binden: ein DateTimeImmutable würde als datetime_immutable
     * (Kimai: UTCDateTimeImmutableType) nach UTC verschoben und träfe den Vortag.
     */
    private static function day(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
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
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('a.status = :status')
            ->andWhere('a.startDate >= :from')
            ->andWhere('a.startDate <= :to')
            ->setParameter('user', $user)
            ->setParameter('status', Absence::STATUS_APPROVED)
            ->setParameter('from', \sprintf('%04d-01-01', $year))
            ->setParameter('to', \sprintf('%04d-12-31', $year))
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All approved absences of the user (für die jahresübergreifende Urlaubsrechnung).
     *
     * @return Absence[]
     */
    public function findApprovedForUser(User $user): array
    {
        return $this->findBy(['user' => $user, 'status' => Absence::STATUS_APPROVED], ['startDate' => 'ASC']);
    }

    /**
     * Approved absences for the user that intersect [from, to] (by date).
     *
     * @return Absence[]
     */
    public function findApprovedForUserInRange(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('a.status = :status')
            ->andWhere('a.startDate <= :to')
            ->andWhere('a.endDate >= :from')
            ->setParameter('user', $user)
            ->setParameter('status', Absence::STATUS_APPROVED)
            ->setParameter('from', self::day($from))
            ->setParameter('to', self::day($to))
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Absences of the user whose date range intersects [start, end] (inclusive),
     * limited to the given statuses, optionally excluding one absence id.
     *
     * @param string[] $statuses
     *
     * @return Absence[]
     */
    public function findOverlappingForUser(User $user, \DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeId, array $statuses): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('a.status IN (:statuses)')
            ->andWhere('a.startDate <= :end')
            ->andWhere('a.endDate >= :start')
            ->setParameter('user', $user)
            ->setParameter('statuses', $statuses)
            ->setParameter('start', self::day($start))
            ->setParameter('end', self::day($end))
            ->orderBy('a.startDate', 'ASC');

        if (null !== $excludeId) {
            $qb->andWhere('a.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Absences of any user whose date range intersects [start, end] (inclusive),
     * limited to the given statuses, optionally excluding one absence id.
     *
     * @param string[] $statuses
     *
     * @return Absence[]
     */
    public function findOverlapping(\DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeId, array $statuses): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.status IN (:statuses)')
            ->andWhere('a.startDate <= :end')
            ->andWhere('a.endDate >= :start')
            ->setParameter('statuses', $statuses)
            ->setParameter('start', self::day($start))
            ->setParameter('end', self::day($end))
            ->orderBy('a.startDate', 'ASC');

        if (null !== $excludeId) {
            $qb->andWhere('a.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Absences with a pending cancellation request.
     *
     * @return Absence[]
     */
    public function findCancellationRequests(): array
    {
        return $this->findBy(['cancelRequested' => true], ['startDate' => 'ASC']);
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
