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
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;

#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\Table(name: 'kimai2_worktime_contract')]
#[ORM\UniqueConstraint(name: 'UNIQ_worktime_contract_user', columns: ['user_id'])]
class Contract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'work_hours_mon', type: Types::INTEGER)]
    private int $workHoursMonday = 0;

    #[ORM\Column(name: 'work_hours_tue', type: Types::INTEGER)]
    private int $workHoursTuesday = 0;

    #[ORM\Column(name: 'work_hours_wed', type: Types::INTEGER)]
    private int $workHoursWednesday = 0;

    #[ORM\Column(name: 'work_hours_thu', type: Types::INTEGER)]
    private int $workHoursThursday = 0;

    #[ORM\Column(name: 'work_hours_fri', type: Types::INTEGER)]
    private int $workHoursFriday = 0;

    #[ORM\Column(name: 'work_hours_sat', type: Types::INTEGER)]
    private int $workHoursSaturday = 0;

    #[ORM\Column(name: 'work_hours_sun', type: Types::INTEGER)]
    private int $workHoursSunday = 0;

    #[ORM\Column(name: 'holidays_per_year', type: Types::FLOAT)]
    private float $holidaysPerYear = 0.0;

    #[ORM\Column(name: 'vacation_carryover', type: Types::FLOAT)]
    private float $vacationCarryover = 0.0;

    #[ORM\Column(name: 'initial_overtime', type: Types::INTEGER)]
    private int $initialOvertimeSeconds = 0;

    #[ORM\Column(name: 'employment_start', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $employmentStart = null;

    #[ORM\Column(name: 'employment_end', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $employmentEnd = null;

    #[ORM\Column(name: 'holiday_region', type: Types::STRING, length: 50)]
    private string $holidayRegion = 'NRW';

    #[ORM\Column(name: 'daily_end_time', type: Types::STRING, length: 5, nullable: true)]
    private ?string $dailyEndTime = null;

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

    /**
     * @param int $isoWeekday 1 (Mon) .. 7 (Sun)
     */
    public function getWorkHoursForWeekday(int $isoWeekday): int
    {
        return match ($isoWeekday) {
            1 => $this->workHoursMonday,
            2 => $this->workHoursTuesday,
            3 => $this->workHoursWednesday,
            4 => $this->workHoursThursday,
            5 => $this->workHoursFriday,
            6 => $this->workHoursSaturday,
            7 => $this->workHoursSunday,
            default => throw new \InvalidArgumentException('ISO weekday must be 1..7, got '.$isoWeekday),
        };
    }

    public function setWorkHoursForWeekday(int $isoWeekday, int $seconds): void
    {
        match ($isoWeekday) {
            1 => $this->workHoursMonday = $seconds,
            2 => $this->workHoursTuesday = $seconds,
            3 => $this->workHoursWednesday = $seconds,
            4 => $this->workHoursThursday = $seconds,
            5 => $this->workHoursFriday = $seconds,
            6 => $this->workHoursSaturday = $seconds,
            7 => $this->workHoursSunday = $seconds,
            default => throw new \InvalidArgumentException('ISO weekday must be 1..7, got '.$isoWeekday),
        };
    }

    public function getWorkHoursForDay(\DateTimeInterface $date): int
    {
        return $this->getWorkHoursForWeekday((int) $date->format('N'));
    }

    public function getHolidaysPerYear(): float
    {
        return $this->holidaysPerYear;
    }

    public function setHolidaysPerYear(float $holidaysPerYear): void
    {
        $this->holidaysPerYear = $holidaysPerYear;
    }

    public function getVacationCarryover(): float
    {
        return $this->vacationCarryover;
    }

    public function setVacationCarryover(float $vacationCarryover): void
    {
        $this->vacationCarryover = $vacationCarryover;
    }

    public function getInitialOvertimeSeconds(): int
    {
        return $this->initialOvertimeSeconds;
    }

    public function setInitialOvertimeSeconds(int $initialOvertimeSeconds): void
    {
        $this->initialOvertimeSeconds = $initialOvertimeSeconds;
    }

    public function getEmploymentStart(): ?\DateTimeImmutable
    {
        return $this->employmentStart;
    }

    public function setEmploymentStart(?\DateTimeImmutable $employmentStart): void
    {
        $this->employmentStart = $employmentStart;
    }

    public function getEmploymentEnd(): ?\DateTimeImmutable
    {
        return $this->employmentEnd;
    }

    public function setEmploymentEnd(?\DateTimeImmutable $employmentEnd): void
    {
        $this->employmentEnd = $employmentEnd;
    }

    public function getHolidayRegion(): string
    {
        return $this->holidayRegion;
    }

    public function setHolidayRegion(string $holidayRegion): void
    {
        $this->holidayRegion = $holidayRegion;
    }

    public function getDailyEndTime(): ?string
    {
        return $this->dailyEndTime;
    }

    public function setDailyEndTime(?string $dailyEndTime): void
    {
        $this->dailyEndTime = $dailyEndTime;
    }
}
