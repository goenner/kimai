<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Account;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\DayOffProvider;
use KimaiPlugin\WorktimeBundle\Calculator\VacationCalculator;
use KimaiPlugin\WorktimeBundle\Repository\AbsenceRepository;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use KimaiPlugin\WorktimeBundle\Validator\DateInput;

/**
 * Urlaubskonto eines Nutzers für ein Jahr (Anspruch, Übertrag aus dem Vorjahr, genommen, Rest).
 */
class VacationAccount
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly AbsenceRepository $absences,
        private readonly VacationCalculator $calc,
        private readonly DayOffProvider $daysOff,
    ) {
    }

    /**
     * @return array{has_contract: bool, entitlement: float, carryover: float, used: float, remaining: float, available_years: int[]}
     */
    public function summary(User $user, int $year): array
    {
        $current = (int) (new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->format('Y');
        $contract = $this->contracts->findForUser($user);
        if (null === $contract) {
            return ['has_contract' => false, 'entitlement' => 0.0, 'carryover' => 0.0, 'used' => 0.0, 'remaining' => 0.0, 'available_years' => range($current, $current + 1)];
        }

        $approved = $this->absences->findApprovedForUser($user);
        $daysOff = [];
        if ([] !== $approved) {
            // Feiertage/freie Tage einmal für den ganzen Zeitraum laden statt je Antrag
            $from = min(array_map(static fn ($a) => $a->getStartDate(), $approved));
            $to = max(array_map(static fn ($a) => $a->getEndDate(), $approved));
            $daysOff = $this->daysOff->between($contract->getHolidayRegion(), $from, $to);
        }
        $usedByYear = [];
        foreach ($approved as $a) {
            $y = (int) $a->getStartDate()->format('Y');
            $usedByYear[$y] = ($usedByYear[$y] ?? 0.0) + $this->calc->countDays($contract, $a->getStartDate(), $a->getEndDate(), $a->isHalfDay(), $daysOff);
        }

        // erstes Jahr der Übertragskette: Eintrittsjahr, sonst das früheste Jahr mit Urlaub, sonst das laufende.
        // Der Stichtag des Zeitkontos (Einführung des Plugins) begrenzt die Kette nach vorn — davor gibt es
        // keine erfassten Urlaube, ein Rest aus diesen Jahren wäre erfunden; der Vertragswert „Übertrag" gilt ab dort.
        $first = null !== $contract->getEmploymentStart()
            ? (int) $contract->getEmploymentStart()->format('Y')
            : ([] === $usedByYear ? $current : min(array_keys($usedByYear)));
        if (null !== $contract->getAccountStart()) {
            $first = max($first, (int) $contract->getAccountStart()->format('Y'));
        }

        if ($year < $first) {
            $entitlement = $this->calc->entitlementForYear($contract, $year);
            $used = $usedByYear[$year] ?? 0.0;
            $row = ['entitlement' => $entitlement, 'carryover' => 0.0, 'used' => $used, 'remaining' => $this->calc->remainingDays($entitlement, 0.0, $used)];
        } else {
            $row = $this->calc->yearlyBalances($contract, $first, $year, $usedByYear)[$year];
        }

        $last = max($current + 1, [] === $usedByYear ? $current : max(array_keys($usedByYear)));

        return ['has_contract' => true] + $row + ['available_years' => range(max(DateInput::MIN_YEAR, min($first, $current)), min(DateInput::MAX_YEAR, $last))];
    }
}
