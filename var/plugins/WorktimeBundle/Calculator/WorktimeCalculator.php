<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Model\DayFacts;

final class WorktimeCalculator
{
    public function targetSeconds(Contract $contract, DayFacts $day): int
    {
        if ($day->publicHoliday) {
            return 0;
        }

        $d = $day->date->format('Y-m-d');

        $start = $contract->getEmploymentStart();
        if (null !== $start && $d < $start->format('Y-m-d')) {
            return 0;
        }

        $end = $contract->getEmploymentEnd();
        if (null !== $end && $d > $end->format('Y-m-d')) {
            return 0;
        }

        return $contract->getWorkHoursForDay($day->date);
    }

    public function creditSeconds(DayFacts $day): int
    {
        return $day->workedSeconds + $day->absenceCreditSeconds;
    }

    public function dailyBalanceSeconds(Contract $contract, DayFacts $day): int
    {
        $target = $this->targetSeconds($contract, $day);

        return ($this->creditSeconds($day) - $target) - $day->overtimeReductionSeconds;
    }

    /**
     * @param iterable<DayFacts> $days
     */
    public function accumulatedBalanceSeconds(Contract $contract, iterable $days, int $correctionSeconds = 0): int
    {
        $balance = $contract->getInitialOvertimeSeconds() + $correctionSeconds;
        foreach ($days as $day) {
            $balance += $this->dailyBalanceSeconds($contract, $day);
        }

        return $balance;
    }
}
