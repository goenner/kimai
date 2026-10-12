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
    public function __construct(private readonly ?DayOffProvider $daysOff = null)
    {
    }

    /**
     * Wie countDays(), berücksichtigt aber Feiertage und freie Tage aus dem Bundesland des Vertrags.
     */
    public function countAbsence(Contract $contract, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $halfDay): float
    {
        $daysOff = null === $this->daysOff ? [] : $this->daysOff->between($contract->getHolidayRegion(), $from, $to);

        return $this->countDays($contract, $from, $to, $halfDay, $daysOff);
    }

    /**
     * Number of vacation days in [from, to] that are working days per the
     * contract (weekday work hours > 0). A half day (only meaningful for a
     * single day) counts 0.5 on a working day, 0 otherwise.
     * Feiertage/freie Tage ($daysOff, Y-m-d => {half}) kosten keinen Urlaub, halbe freie Tage höchstens 0,5.
     *
     * @param array<string, array{name: string, half: bool}> $daysOff
     */
    public function countDays(Contract $contract, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $halfDay, array $daysOff = []): float
    {
        $start = $from->setTime(0, 0);
        $end = $halfDay ? $start : $to->setTime(0, 0);

        $days = 0.0;
        $cursor = $start;
        while ($cursor <= $end) {
            $off = $daysOff[$cursor->format('Y-m-d')] ?? null;
            if ($contract->getWorkHoursForDay($cursor) > 0 && (null === $off || $off['half'])) {
                $days += ($halfDay || null !== $off) ? 0.5 : 1.0;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    public function remainingDays(float $entitlement, float $carryover, float $usedDays): float
    {
        return $entitlement + $carryover - $usedDays;
    }

    /**
     * Jahresanspruch; bei unterjährigem Ein- oder Austritt anteilig nach vollen Beschäftigungsmonaten
     * des Jahres, auf halbe Tage gerundet.
     */
    public function entitlementForYear(Contract $contract, int $year): float
    {
        $start = $contract->getEmploymentStart()?->format('Y-m-d');
        $end = $contract->getEmploymentEnd()?->format('Y-m-d');

        $months = 0;
        for ($m = 1; $m <= 12; ++$m) {
            $first = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $m));
            if ((null === $start || $start <= $first->format('Y-m-d')) && (null === $end || $end >= $first->format('Y-m-t'))) {
                ++$months;
            }
        }

        if (12 === $months) {
            return $contract->getHolidaysPerYear();
        }

        return round($contract->getHolidaysPerYear() * $months / 12 * 2) / 2;
    }

    /**
     * Urlaubskonto je Jahr von $firstYear bis $toYear. Übertrag ins erste Jahr ist der Vertragswert
     * (vacationCarryover), in jedes spätere Jahr der Rest des Vorjahres — auch ein negativer.
     *
     * @param array<int, float> $usedByYear genehmigte Urlaubstage je Jahr
     *
     * @return array<int, array{entitlement: float, carryover: float, used: float, remaining: float}>
     */
    public function yearlyBalances(Contract $contract, int $firstYear, int $toYear, array $usedByYear): array
    {
        $result = [];
        $carryover = $contract->getVacationCarryover();
        for ($year = $firstYear; $year <= $toYear; ++$year) {
            $entitlement = $this->entitlementForYear($contract, $year);
            $used = $usedByYear[$year] ?? 0.0;
            $remaining = $this->remainingDays($entitlement, $carryover, $used);
            $result[$year] = ['entitlement' => $entitlement, 'carryover' => $carryover, 'used' => $used, 'remaining' => $remaining];
            $carryover = $remaining;
        }

        return $result;
    }
}
