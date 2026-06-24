<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Account;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\AccountBuilder;
use KimaiPlugin\WorktimeBundle\Model\DayAccount;
use KimaiPlugin\WorktimeBundle\Repository\AbsenceRepository;
use KimaiPlugin\WorktimeBundle\Repository\BalanceCorrectionRepository;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;

class AccountService
{
    public function __construct(
        private readonly AccountBuilder $builder,
        private readonly ContractRepository $contracts,
        private readonly WorkBlockRepository $blocks,
        private readonly AbsenceRepository $absences,
        private readonly BalanceCorrectionRepository $corrections,
    ) {
    }

    /**
     * @return array{days: DayAccount[], targetSeconds: int, workedSeconds: int, balanceSeconds: int, cumulativeSeconds: int, has_contract: bool}
     */
    public function monthAccount(User $user, int $year, int $month): array
    {
        $contract = $this->contracts->findForUser($user);
        $tz = new \DateTimeZone($user->getTimezone());
        $monthStart = new \DateTimeImmutable(\sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        if (null === $contract) {
            return ['days' => [], 'targetSeconds' => 0, 'workedSeconds' => 0, 'balanceSeconds' => 0, 'cumulativeSeconds' => 0, 'has_contract' => false];
        }

        $days = $this->buildDays($user, $contract, $monthStart, $monthStart->modify('last day of this month')->setTime(0, 0), $tz);

        $target = 0;
        $worked = 0;
        $balance = 0;
        foreach ($days as $d) {
            $target += $d->targetSeconds;
            $worked += $d->workedSeconds;
            $balance += $d->balanceSeconds;
        }

        return [
            'days' => $days,
            'targetSeconds' => $target,
            'workedSeconds' => $worked,
            'balanceSeconds' => $balance,
            'cumulativeSeconds' => $this->cumulativeBalance($user, $contract, $monthEnd, $tz),
            'has_contract' => true,
        ];
    }

    /**
     * @return array{months: array<int, array{targetSeconds: int, workedSeconds: int, balanceSeconds: int}>, cumulativeSeconds: int, has_contract: bool}
     */
    public function yearAccount(User $user, int $year): array
    {
        $months = [];
        $hasContract = null !== $this->contracts->findForUser($user);
        $cumulative = 0;
        for ($m = 1; $m <= 12; ++$m) {
            $data = $this->monthAccount($user, $year, $m);
            $months[$m] = [
                'targetSeconds' => $data['targetSeconds'],
                'workedSeconds' => $data['workedSeconds'],
                'balanceSeconds' => $data['balanceSeconds'],
            ];
            $cumulative = $data['cumulativeSeconds'];
        }

        return ['months' => $months, 'cumulativeSeconds' => $cumulative, 'has_contract' => $hasContract];
    }

    /**
     * Soll/Ist/Saldo summed over an arbitrary calendar range [from, to] (e.g. a week).
     * The calendar dates are anchored in the user timezone, like monthAccount().
     *
     * @return array{targetSeconds: int, workedSeconds: int, balanceSeconds: int, has_contract: bool}
     */
    public function rangeSummary(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $contract = $this->contracts->findForUser($user);
        if (null === $contract) {
            return ['targetSeconds' => 0, 'workedSeconds' => 0, 'balanceSeconds' => 0, 'has_contract' => false];
        }

        $tz = new \DateTimeZone($user->getTimezone());
        $start = new \DateTimeImmutable($from->format('Y-m-d').' 00:00:00', $tz);
        $end = new \DateTimeImmutable($to->format('Y-m-d').' 00:00:00', $tz);
        $days = $this->buildDays($user, $contract, $start, $end, $tz);

        $target = 0;
        $worked = 0;
        $balance = 0;
        foreach ($days as $d) {
            $target += $d->targetSeconds;
            $worked += $d->workedSeconds;
            $balance += $d->balanceSeconds;
        }

        return ['targetSeconds' => $target, 'workedSeconds' => $worked, 'balanceSeconds' => $balance, 'has_contract' => true];
    }

    /**
     * Build a DayAccount for every calendar day in [from, to] (both at 00:00 local).
     *
     * @return DayAccount[]
     */
    private function buildDays(User $user, \KimaiPlugin\WorktimeBundle\Entity\Contract $contract, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $tz): array
    {
        $rangeStart = $from->setTime(0, 0);
        $rangeEnd = $to->setTime(23, 59, 59);
        $blocks = $this->blocks->findForUserBetween($user, $rangeStart, $rangeEnd->modify('+1 second'));
        $absences = $this->absences->findApprovedForUserInRange($user, $rangeStart, $rangeEnd);

        // group worked seconds per local day
        $workedByDay = [];
        foreach ($blocks as $block) {
            $key = $block->getStart()->setTimezone($tz)->format('Y-m-d');
            $workedByDay[$key] = ($workedByDay[$key] ?? 0) + ($block->getDurationSeconds() ?? 0);
        }

        $days = [];
        $cursor = $rangeStart;
        $last = $to->setTime(0, 0);
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            $days[] = $this->builder->dayAccount($contract, $cursor, $workedByDay[$key] ?? 0, $absences);
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    private function cumulativeBalance(User $user, \KimaiPlugin\WorktimeBundle\Entity\Contract $contract, \DateTimeImmutable $upTo, \DateTimeZone $tz): int
    {
        $start = $contract->getEmploymentStart();
        if (null === $start) {
            // fall back to the first day of the displayed month's year
            $start = $upTo->setDate((int) $upTo->format('Y'), 1, 1);
        }
        $start = $start->setTimezone($tz)->setTime(0, 0);
        $end = $upTo->setTimezone($tz)->setTime(0, 0);
        if ($end < $start) {
            return $contract->getInitialOvertimeSeconds();
        }

        $days = $this->buildDays($user, $contract, $start, $end, $tz);
        $sum = $contract->getInitialOvertimeSeconds();
        foreach ($days as $d) {
            $sum += $d->balanceSeconds;
        }
        foreach ($this->corrections->findOvertimeForUserUpTo($user, $upTo) as $correction) {
            $sum += $correction->getSeconds();
        }

        return $sum;
    }
}
