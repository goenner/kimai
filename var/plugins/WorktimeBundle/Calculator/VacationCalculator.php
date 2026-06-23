<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

use KimaiPlugin\WorktimeBundle\Entity\Contract;

final class VacationCalculator
{
    /**
     * Number of vacation days in [from, to] that are working days per the
     * contract (weekday work hours > 0). A half day (only meaningful for a
     * single day) counts 0.5 on a working day, 0 otherwise.
     */
    public function countDays(Contract $contract, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $halfDay): float
    {
        $start = $from->setTime(0, 0);
        $end = $to->setTime(0, 0);

        if ($halfDay) {
            $weekday = (int) $start->format('N');

            return $contract->getWorkHoursForWeekday($weekday) > 0 ? 0.5 : 0.0;
        }

        $days = 0.0;
        $cursor = $start;
        while ($cursor <= $end) {
            $weekday = (int) $cursor->format('N');
            if ($contract->getWorkHoursForWeekday($weekday) > 0) {
                ++$days;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    public function remainingDays(float $entitlement, float $carryover, float $usedDays): float
    {
        return $entitlement + $carryover - $usedDays;
    }
}
