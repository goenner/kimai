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
}
