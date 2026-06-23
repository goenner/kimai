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
     */
    public function dayAccount(Contract $contract, \DateTimeImmutable $date, int $workedSeconds, array $approvedAbsences): DayAccount
    {
        $day = $date->setTime(0, 0);
        $target = $this->calculator->targetSeconds($contract, new DayFacts($day));

        $credit = 0;
        $note = null;
        foreach ($approvedAbsences as $absence) {
            if (!$this->covers($absence, $day)) {
                continue;
            }
            if ($absence->isHalfDay()) {
                $credit = (int) round($target / 2);
                $note = '½ Urlaub';
            } else {
                $credit = $target;
                $note = 'Urlaub';
            }
            break;
        }

        $facts = new DayFacts($day, $workedSeconds, false, $credit, 0);
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
