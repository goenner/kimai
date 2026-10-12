<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Account;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\AccountBuilder;
use KimaiPlugin\WorktimeBundle\Calculator\DayOffProvider;
use KimaiPlugin\WorktimeBundle\Calculator\WorkBlockMath;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
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
        private readonly DayOffProvider $daysOff,
        private readonly WorkBlockMath $math,
        private readonly ?\DateTimeImmutable $now = null, // nur für Tests, sonst „jetzt"
    ) {
    }

    /**
     * @return array{days: DayAccount[], targetSeconds: int, workedSeconds: int, balanceSeconds: int, cumulativeSeconds: int, has_contract: bool}
     */
    public function monthAccount(User $user, int $year, int $month): array
    {
        $contract = $this->contracts->findForUser($user);
        if (null === $contract) {
            return ['days' => [], 'targetSeconds' => 0, 'workedSeconds' => 0, 'balanceSeconds' => 0, 'cumulativeSeconds' => 0, 'has_contract' => false];
        }

        $tz = new \DateTimeZone($user->getTimezone());
        [$monthStart, $monthLast] = $this->monthRange($year, $month, $tz);
        [$days, $cumulative] = $this->account($user, $contract, $tz, $monthStart, $monthLast, $monthLast);

        return $this->sum($days) + ['days' => $days, 'cumulativeSeconds' => $cumulative, 'has_contract' => true];
    }

    /**
     * @return array{months: array<int, array{targetSeconds: int, workedSeconds: int, balanceSeconds: int}>, cumulativeSeconds: int, has_contract: bool}
     */
    public function yearAccount(User $user, int $year): array
    {
        $contract = $this->contracts->findForUser($user);
        $byMonth = array_fill(1, 12, []);
        $cumulative = 0;
        if (null !== $contract) {
            $tz = new \DateTimeZone($user->getTimezone());
            $from = new \DateTimeImmutable(\sprintf('%04d-01-01 00:00:00', $year), $tz);
            $to = new \DateTimeImmutable(\sprintf('%04d-12-31 00:00:00', $year), $tz);
            [$days, $cumulative] = $this->account($user, $contract, $tz, $from, $to, $to);
            foreach ($days as $d) {
                $byMonth[(int) $d->date->format('n')][] = $d;
            }
        }

        $months = [];
        foreach ($byMonth as $m => $days) {
            $months[$m] = $this->sum($days);
        }

        return ['months' => $months, 'cumulativeSeconds' => $cumulative, 'has_contract' => null !== $contract];
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
        [$days] = $this->account($user, $contract, $tz, $this->localDay($from, $tz), $this->localDay($to, $tz), null);

        return $this->sum($days) + ['has_contract' => true];
    }

    /**
     * Teamübersicht: Woche + Monat (inkl. kumuliertem Saldo) eines Nutzers aus einem einzigen Datenladen.
     * Liefert dieselben Strukturen wie rangeSummary() und monthAccount().
     *
     * @return array{week: array{targetSeconds: int, workedSeconds: int, balanceSeconds: int, has_contract: bool}, month: array{days: DayAccount[], targetSeconds: int, workedSeconds: int, balanceSeconds: int, cumulativeSeconds: int, has_contract: bool}}
     */
    public function overviewRow(User $user, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd, int $year, int $month): array
    {
        $contract = $this->contracts->findForUser($user);
        if (null === $contract) {
            return [
                'week' => ['targetSeconds' => 0, 'workedSeconds' => 0, 'balanceSeconds' => 0, 'has_contract' => false],
                'month' => ['days' => [], 'targetSeconds' => 0, 'workedSeconds' => 0, 'balanceSeconds' => 0, 'cumulativeSeconds' => 0, 'has_contract' => false],
            ];
        }

        $tz = new \DateTimeZone($user->getTimezone());
        [$monthStart, $monthLast] = $this->monthRange($year, $month, $tz);
        $weekFrom = $this->localDay($weekStart, $tz);
        $weekTo = $this->localDay($weekEnd, $tz);
        [$days, $cumulative] = $this->account($user, $contract, $tz, min($weekFrom, $monthStart), max($weekTo, $monthLast), $monthLast);

        $week = [];
        $monthDays = [];
        foreach ($days as $d) {
            if ($d->date >= $weekFrom && $d->date <= $weekTo) {
                $week[] = $d;
            }
            if ($d->date >= $monthStart && $d->date <= $monthLast) {
                $monthDays[] = $d;
            }
        }

        return [
            'week' => $this->sum($week) + ['has_contract' => true],
            'month' => $this->sum($monthDays) + ['days' => $monthDays, 'cumulativeSeconds' => $cumulative, 'has_contract' => true],
        ];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} erster und letzter Tag des Monats, 00:00 lokal
     */
    private function monthRange(int $year, int $month, \DateTimeZone $tz): array
    {
        $start = new \DateTimeImmutable(\sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);

        return [$start, $start->modify('last day of this month')];
    }

    private function localDay(\DateTimeImmutable $date, \DateTimeZone $tz): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d').' 00:00:00', $tz);
    }

    /**
     * @param DayAccount[] $days
     *
     * @return array{targetSeconds: int, workedSeconds: int, balanceSeconds: int}
     */
    private function sum(array $days): array
    {
        $r = ['targetSeconds' => 0, 'workedSeconds' => 0, 'balanceSeconds' => 0];
        foreach ($days as $d) {
            $r['targetSeconds'] += $d->targetSeconds;
            $r['workedSeconds'] += $d->workedSeconds;
            $r['balanceSeconds'] += $d->balanceSeconds;
        }

        return $r;
    }

    /**
     * Tage [from, to] plus kumulierter Saldo bis min($cumulativeUpTo, heute) (null = ohne Saldo), alles
     * aus einem einzigen Datenladen über den vereinigten Zeitraum.
     *
     * @return array{0: DayAccount[], 1: int}
     */
    private function account(User $user, Contract $contract, \DateTimeZone $tz, \DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $cumulativeUpTo): array
    {
        $cumStart = null;
        $cumEnd = null;
        if (null !== $cumulativeUpTo) {
            $cumStart = $this->cumulativeStart($user, $contract, $tz);
            $cumEnd = min($cumulativeUpTo, $this->today($tz)->modify('-1 day'));
        }

        $lo = $from;
        $hi = $to;
        if (null !== $cumStart && null !== $cumEnd && $cumStart <= $cumEnd) {
            $lo = min($lo, $cumStart);
            $hi = max($hi, $cumEnd);
        }

        $days = [];
        $cumulative = $contract->getInitialOvertimeSeconds();
        foreach ($this->buildDays($user, $contract, $lo, $hi, $tz) as $d) {
            if ($d->date >= $from && $d->date <= $to) {
                $days[] = $d;
            }
            if (null !== $cumStart && null !== $cumEnd && $d->date >= $cumStart && $d->date <= $cumEnd) {
                $cumulative += $d->balanceSeconds;
            }
        }

        if (null !== $cumStart && null !== $cumulativeUpTo) {
            // Korrekturen wirken ab ihrem Buchungstag (auch heute); die vor dem Saldo-Beginn gehören zur verworfenen Historie
            foreach ($this->corrections->findOvertimeForUserUpTo($user, min($cumulativeUpTo, $this->today($tz))) as $correction) {
                if ($correction->getDate()->format('Y-m-d') >= $cumStart->format('Y-m-d')) {
                    $cumulative += $correction->getSeconds();
                }
            }
        }

        return [$days, $cumulative];
    }

    /**
     * Build a DayAccount for every calendar day in [from, to] (both at 00:00 local).
     *
     * @return DayAccount[]
     */
    private function buildDays(User $user, Contract $contract, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $tz): array
    {
        $rangeStart = $from->setTime(0, 0);
        $rangeEnd = $to->setTime(23, 59, 59);
        // alle Blöcke, die den Zeitraum berühren (auch über Mitternacht hineinragende), je Tag aufgeteilt
        $blocks = $this->blocks->findOverlapping($user, $rangeStart, $rangeEnd->modify('+1 second'), null);
        $workedByDay = $this->math->secondsPerLocalDay($blocks, $tz);
        $absences = $this->absences->findApprovedForUserInRange($user, $rangeStart, $rangeEnd);
        $daysOff = $this->daysOff->between($contract->getHolidayRegion(), $rangeStart, $rangeEnd);
        $todayKey = $this->today($tz)->format('Y-m-d');
        $accountStartKey = $contract->getAccountStart()?->format('Y-m-d');

        $days = [];
        $cursor = $rangeStart;
        $last = $to->setTime(0, 0);
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            $d = $this->builder->dayAccount($contract, $cursor, $workedByDay[$key] ?? 0, $absences, $daysOff[$key] ?? null);
            if (($key >= $todayKey || (null !== $accountStartKey && $key < $accountStartKey)) && 0 !== $d->balanceSeconds) {
                // heute (läuft noch), künftige Tage und Tage vor dem Stichtag: Soll wird ausgewiesen, fließt aber nicht in den Saldo ein
                $d = new DayAccount($d->date, $d->targetSeconds, $d->workedSeconds, $d->creditSeconds, 0, $d->note);
            }
            $days[] = $d;
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    private function today(\DateTimeZone $tz): \DateTimeImmutable
    {
        return ($this->now ?? new \DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0);
    }

    /**
     * Beginn des kumulierten Saldos: Beschäftigungsbeginn, sonst der früheste Block bzw. die
     * früheste Korrektur; null, wenn es nichts zu rechnen gibt.
     */
    private function cumulativeStart(User $user, Contract $contract, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $start = $this->naturalStart($user, $contract, $tz);
        $accountStart = $contract->getAccountStart();
        if (null === $accountStart) {
            return $start;
        }
        $accountStart = $this->localDay($accountStart, $tz);

        return null === $start ? $accountStart : max($start, $accountStart);
    }

    private function naturalStart(User $user, Contract $contract, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $employment = $contract->getEmploymentStart();
        if (null !== $employment) {
            return $this->localDay($employment, $tz);
        }

        $candidates = [];
        $firstBlock = $this->blocks->findFirstStart($user);
        if (null !== $firstBlock) {
            $candidates[] = $this->localDay($firstBlock->setTimezone($tz), $tz);
        }
        $firstCorrection = $this->corrections->findFirstDate($user);
        if (null !== $firstCorrection) {
            $candidates[] = $this->localDay($firstCorrection, $tz);
        }

        return [] === $candidates ? null : min($candidates);
    }
}
