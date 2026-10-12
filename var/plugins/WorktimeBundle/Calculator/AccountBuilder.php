<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

use KimaiPlugin\WorktimeBundle\Entity\Absence;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Model\DayAccount;
use KimaiPlugin\WorktimeBundle\Model\DayFacts;

final class AccountBuilder
{
    public function __construct(private readonly WorktimeCalculator $calculator)
    {
    }

    /**
     * @param Absence[] $approvedAbsences
     * @param array{name: string, half: bool}|null $dayOff Feiertag oder vom Admin eingetragener freier Tag
     */
    public function dayAccount(Contract $contract, \DateTimeImmutable $date, int $workedSeconds, array $approvedAbsences, ?array $dayOff = null): DayAccount
    {
        $day = $date->setTime(0, 0);
        $holiday = null !== $dayOff && !$dayOff['half'];
        $halfOff = null !== $dayOff && $dayOff['half'];
        $target = $this->calculator->targetSeconds($contract, new DayFacts($day, 0, $holiday, 0, 0, $halfOff));

        $credit = 0;
        $note = null === $dayOff ? null : ($halfOff ? '½ ' : '').$dayOff['name'];
        $absenceNote = null;
        // Mehrere Abwesenheiten am selben Tag: die höchste Gutschrift zählt
        foreach ($approvedAbsences as $absence) {
            if ($target <= 0 || !$this->covers($absence, $day)) {
                continue;
            }
            // Ein halber Urlaubstag an einem halben freien Tag deckt den Rest des Tages ab
            $half = $absence->isHalfDay() && !$halfOff;
            $c = $half ? (int) round($target / 2) : $target;
            if ($c > $credit) {
                $credit = $c;
                $absenceNote = $half ? '½ Urlaub' : (null === $note ? 'Urlaub' : $note.' · Urlaub');
            }
        }
        $note = $absenceNote ?? $note;

        $facts = new DayFacts($day, $workedSeconds, $holiday, $credit, 0, $halfOff);
        $balance = $this->calculator->dailyBalanceSeconds($contract, $facts);

        return new DayAccount($day, $target, $workedSeconds, $credit, $balance, $note);
    }

    private function covers(Absence $absence, \DateTimeImmutable $day): bool
    {
        $d = $day->format('Y-m-d');

        return $d >= $absence->getStartDate()->format('Y-m-d')
            && $d <= $absence->getEndDate()->format('Y-m-d');
    }
}
