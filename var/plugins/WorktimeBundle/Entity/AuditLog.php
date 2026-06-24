<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\WorktimeBundle\Repository\AuditLogRepository;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'kimai2_worktime_audit_log')]
#[ORM\Index(columns: ['target_id', 'created_at'], name: 'IDX_worktime_audit_target')]
class AuditLog
{
    public const ACTION_PUNCH_IN = 'punch_in';
    public const ACTION_PUNCH_OUT = 'punch_out';
    public const ACTION_BLOCK_CREATE = 'block_create';
    public const ACTION_BLOCK_EDIT = 'block_edit';
    public const ACTION_BLOCK_DELETE = 'block_delete';
    public const ACTION_ABSENCE_REQUEST = 'absence_request';
    public const ACTION_ABSENCE_APPROVE = 'absence_approve';
    public const ACTION_ABSENCE_REJECT = 'absence_reject';
    public const ACTION_BALANCE_CORRECTION = 'balance_correction';
    public const ACTION_BLOCK_AUTO_CLOSE = 'block_auto_close';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'target_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $targetUser = null;

    #[ORM\Column(name: 'action', type: Types::STRING, length: 40)]
    private string $action;

    #[ORM\Column(name: 'entity_type', type: Types::STRING, length: 40)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', type: Types::INTEGER, nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column(name: 'details', type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    public function __construct(\DateTimeImmutable $createdAt, string $action, string $entityType)
    {
        $this->createdAt = $createdAt;
        $this->action = $action;
        $this->entityType = $entityType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): void
    {
        $this->actor = $actor;
    }

    public function getTargetUser(): ?User
    {
        return $this->targetUser;
    }

    public function setTargetUser(?User $targetUser): void
    {
        $this->targetUser = $targetUser;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): void
    {
        $this->entityId = $entityId;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): void
    {
        $this->details = $details;
    }
}
