<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\WorktimeBundle\Repository\FreeDayRepository;

/**
 * Vom Admin eingetragener freier Tag (ganz oder halb), z. B. Heiligabend, Brückentag, Betriebsausflug.
 * region = null gilt für alle Mitarbeitenden, sonst nur für Verträge mit diesem Bundesland.
 */
#[ORM\Entity(repositoryClass: FreeDayRepository::class)]
#[ORM\Table(name: 'kimai2_worktime_free_day')]
#[ORM\Index(columns: ['day'], name: 'IDX_worktime_free_day_day')]
class FreeDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'day', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 100)]
    private string $name;

    #[ORM\Column(name: 'half_day', type: Types::BOOLEAN)]
    private bool $halfDay = false;

    #[ORM\Column(name: 'region', type: Types::STRING, length: 2, nullable: true)]
    private ?string $region = null;

    public function __construct(\DateTimeImmutable $day, string $name, bool $halfDay = false, ?string $region = null)
    {
        $this->day = $day;
        $this->name = $name;
        $this->halfDay = $halfDay;
        $this->region = $region;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isHalfDay(): bool
    {
        return $this->halfDay;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }
}
