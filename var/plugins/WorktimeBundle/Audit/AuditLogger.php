<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Audit;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;

class AuditLogger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function log(?User $actor, ?User $target, string $action, string $entityType, ?int $entityId, array $details = []): void
    {
        $log = new AuditLog(new \DateTimeImmutable('now'), $action, $entityType);
        $log->setActor($actor);
        $log->setTargetUser($target);
        $log->setEntityId($entityId);
        $log->setDetails([] === $details ? null : json_encode($details, JSON_THROW_ON_ERROR));

        $this->em->persist($log);
        $this->em->flush();
    }
}
