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
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;

#[ORM\Entity(repositoryClass: WorkBlockRepository::class)]
#[ORM\Table(name: 'kimai2_worktime_work_block')]
#[ORM\Index(columns: ['user_id', 'start_time'], name: 'IDX_worktime_block_user_start')]
class WorkBlock
{
    public const SOURCE_PUNCH = 'punch';
    public const SOURCE_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'start_time', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $start;

    #[ORM\Column(name: 'end_time', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $end = null;

    #[ORM\Column(name: 'source', type: Types::STRING, length: 10)]
    private string $source = self::SOURCE_PUNCH;

    #[ORM\Column(name: 'needs_review', type: Types::BOOLEAN)]
    private bool $needsReview = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getStart(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function setStart(\DateTimeImmutable $start): void
    {
        $this->start = $start;
    }

    public function getEnd(): ?\DateTimeImmutable
    {
        return $this->end;
    }

    public function setEnd(?\DateTimeImmutable $end): void
    {
        $this->end = $end;
    }

    public function isOpen(): bool
    {
        return null === $this->end;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function isNeedsReview(): bool
    {
        return $this->needsReview;
    }

    public function setNeedsReview(bool $needsReview): void
    {
        $this->needsReview = $needsReview;
    }

    public function getDurationSeconds(): ?int
    {
        if (null === $this->end) {
            return null;
        }

        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }
}
