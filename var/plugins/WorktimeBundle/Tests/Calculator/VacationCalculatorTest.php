<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\VacationCalculator;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use PHPUnit\Framework\TestCase;

class VacationCalculatorTest extends TestCase
{
    private const H8 = 28800;

    private function fullTimeContract(): Contract
    {
        $c = new Contract();
        $c->setUser(new User());
        foreach ([1, 2, 3, 4, 5] as $wd) {
            $c->setWorkHoursForWeekday($wd, self::H8);
        }
        $c->setWorkHoursForWeekday(6, 0);
        $c->setWorkHoursForWeekday(7, 0);

        return $c;
    }

    public function testCountsWorkingDaysMonToFri(): void
    {
        $calc = new VacationCalculator();
        $from = new \DateTimeImmutable('2026-06-01'); // Monday
        $to = new \DateTimeImmutable('2026-06-05');   // Friday
        self::assertSame(5.0, $calc->countDays($this->fullTimeContract(), $from, $to, false));
    }

    public function testExcludesWeekend(): void
    {
        $calc = new VacationCalculator();
        $from = new \DateTimeImmutable('2026-06-05'); // Friday
        $to = new \DateTimeImmutable('2026-06-08');   // Monday (Sat+Sun excluded)
        self::assertSame(2.0, $calc->countDays($this->fullTimeContract(), $from, $to, false));
    }

    public function testSingleWorkingDayFull(): void
    {
        $calc = new VacationCalculator();
        $d = new \DateTimeImmutable('2026-06-01'); // Monday
        self::assertSame(1.0, $calc->countDays($this->fullTimeContract(), $d, $d, false));
    }

    public function testSingleWorkingDayHalf(): void
    {
        $calc = new VacationCalculator();
        $d = new \DateTimeImmutable('2026-06-01'); // Monday
        self::assertSame(0.5, $calc->countDays($this->fullTimeContract(), $d, $d, true));
    }

    public function testWeekendDayIsZero(): void
    {
        $calc = new VacationCalculator();
        $d = new \DateTimeImmutable('2026-06-06'); // Saturday
        self::assertSame(0.0, $calc->countDays($this->fullTimeContract(), $d, $d, false));
    }

    public function testHalfDayOnWeekendIsZero(): void
    {
        $calc = new VacationCalculator();
        $d = new \DateTimeImmutable('2026-06-06'); // Saturday
        self::assertSame(0.0, $calc->countDays($this->fullTimeContract(), $d, $d, true));
    }

    public function testRemainingDays(): void
    {
        $calc = new VacationCalculator();
        self::assertSame(18.5, $calc->remainingDays(30.0, 0.0, 11.5));
    }

    public function testEntitlementFullYearWithoutEmploymentDates(): void
    {
        $c = $this->fullTimeContract();
        $c->setHolidaysPerYear(30.0);
        self::assertSame(30.0, (new VacationCalculator())->entitlementForYear($c, 2026));
    }

    public function testEntitlementProratedByFullMonthsAndHalfDays(): void
    {
        $c = $this->fullTimeContract();
        $c->setHolidaysPerYear(30.0);
        $c->setEmploymentStart(new \DateTimeImmutable('2026-03-15')); // volle Monate April–Dezember = 9
        $calc = new VacationCalculator();
        self::assertSame(22.5, $calc->entitlementForYear($c, 2026));
        self::assertSame(30.0, $calc->entitlementForYear($c, 2027));
        self::assertSame(0.0, $calc->entitlementForYear($c, 2025));

        $c->setEmploymentStart(new \DateTimeImmutable('2026-03-01')); // März zählt voll = 10 Monate
        self::assertSame(25.0, $calc->entitlementForYear($c, 2026));

        $c->setHolidaysPerYear(28.0);
        $c->setEmploymentStart(null);
        $c->setEmploymentEnd(new \DateTimeImmutable('2026-05-15')); // Januar–April = 4 Monate -> 9,33 -> 9,5
        self::assertSame(9.5, $calc->entitlementForYear($c, 2026));
    }

    public function testYearlyBalancesCarryRestIncludingNegative(): void
    {
        $c = $this->fullTimeContract();
        $c->setHolidaysPerYear(24.0);
        $c->setVacationCarryover(3.0);
        $b = (new VacationCalculator())->yearlyBalances($c, 2025, 2027, [2025 => 20.0, 2026 => 30.0]);

        self::assertSame(['entitlement' => 24.0, 'carryover' => 3.0, 'used' => 20.0, 'remaining' => 7.0], $b[2025]);
        self::assertSame(['entitlement' => 24.0, 'carryover' => 7.0, 'used' => 30.0, 'remaining' => 1.0], $b[2026]);
        self::assertSame(1.0, $b[2027]['carryover']);
        self::assertSame(25.0, $b[2027]['remaining']);

        $neg = (new VacationCalculator())->yearlyBalances($c, 2025, 2026, [2025 => 30.0]);
        self::assertSame(-3.0, $neg[2026]['carryover']);
        self::assertSame(21.0, $neg[2026]['remaining']);
    }
}
