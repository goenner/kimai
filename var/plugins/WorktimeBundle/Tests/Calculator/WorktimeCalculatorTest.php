<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\WorktimeCalculator;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Model\DayFacts;
use PHPUnit\Framework\TestCase;

class WorktimeCalculatorTest extends TestCase
{
    private const H8 = 28800;  // 8h
    private const H9 = 32400;  // 9h

    private function contract(): Contract
    {
        $c = new Contract();
        $c->setUser(new User());
        // Mon-Fri 8h, weekend 0
        foreach ([1, 2, 3, 4, 5] as $wd) {
            $c->setWorkHoursForWeekday($wd, self::H8);
        }
        $c->setWorkHoursForWeekday(6, 0);
        $c->setWorkHoursForWeekday(7, 0);
        $c->setEmploymentStart(new \DateTimeImmutable('2026-01-01'));

        return $c;
    }

    private function monday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-01'); // a Monday
    }

    private function saturday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-06'); // a Saturday
    }

    public function testTargetIsContractHoursOnWorkday(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday());
        self::assertSame(self::H8, $calc->targetSeconds($this->contract(), $facts));
    }

    public function testTargetIsZeroOnWeekend(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->saturday());
        self::assertSame(0, $calc->targetSeconds($this->contract(), $facts));
    }

    public function testTargetIsZeroOnPublicHoliday(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday(), publicHoliday: true);
        self::assertSame(0, $calc->targetSeconds($this->contract(), $facts));
    }

    public function testTargetIsZeroBeforeEmploymentStart(): void
    {
        $calc = new WorktimeCalculator();
        $c = $this->contract();
        $c->setEmploymentStart(new \DateTimeImmutable('2026-07-01'));
        $facts = new DayFacts($this->monday()); // 2026-06-01, before start
        self::assertSame(0, $calc->targetSeconds($c, $facts));
    }

    public function testTargetIsZeroAfterEmploymentEnd(): void
    {
        $calc = new WorktimeCalculator();
        $c = $this->contract();
        $c->setEmploymentEnd(new \DateTimeImmutable('2026-05-31'));
        $facts = new DayFacts($this->monday()); // 2026-06-01, after end
        self::assertSame(0, $calc->targetSeconds($c, $facts));
    }

    public function testDailyBalanceNeutralWhenWorkedEqualsTarget(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday(), workedSeconds: self::H8);
        self::assertSame(0, $calc->dailyBalanceSeconds($this->contract(), $facts));
    }

    public function testDailyBalancePositiveOnOvertime(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday(), workedSeconds: self::H9);
        self::assertSame(3600, $calc->dailyBalanceSeconds($this->contract(), $facts));
    }

    public function testVacationDayIsBalanceNeutral(): void
    {
        // worked 0, but absence credit equals target -> neutral
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday(), workedSeconds: 0, absenceCreditSeconds: self::H8);
        self::assertSame(0, $calc->dailyBalanceSeconds($this->contract(), $facts));
    }

    public function testOvertimeReductionDayLowersBalanceByTarget(): void
    {
        // Freizeitausgleich: credit neutralises soll, but overtime balance drops by target
        $calc = new WorktimeCalculator();
        $facts = new DayFacts(
            $this->monday(),
            workedSeconds: 0,
            absenceCreditSeconds: self::H8,
            overtimeReductionSeconds: self::H8
        );
        self::assertSame(-self::H8, $calc->dailyBalanceSeconds($this->contract(), $facts));
    }

    public function testTargetCountsDayExactlyOnEmploymentStart(): void
    {
        $calc = new WorktimeCalculator();
        $c = $this->contract();
        $c->setEmploymentStart(new \DateTimeImmutable('2026-06-01')); // the Monday
        $facts = new DayFacts($this->monday()); // 2026-06-01
        self::assertSame(self::H8, $calc->targetSeconds($c, $facts));
    }

    public function testTargetCountsDayExactlyOnEmploymentEnd(): void
    {
        $calc = new WorktimeCalculator();
        $c = $this->contract();
        $c->setEmploymentEnd(new \DateTimeImmutable('2026-06-01')); // the Monday
        $facts = new DayFacts($this->monday()); // 2026-06-01
        self::assertSame(self::H8, $calc->targetSeconds($c, $facts));
    }

    public function testAccumulatedBalanceSumsDaysPlusInitialAndCorrection(): void
    {
        $calc = new WorktimeCalculator();
        $c = $this->contract();
        $c->setInitialOvertimeSeconds(3600); // +1h carry-in
        $days = [
            new DayFacts($this->monday(), workedSeconds: self::H9),                 // +1h
            new DayFacts(new \DateTimeImmutable('2026-06-02'), workedSeconds: self::H8), // 0
            new DayFacts(new \DateTimeImmutable('2026-06-03'), workedSeconds: 0, absenceCreditSeconds: self::H8), // vacation, 0
        ];
        // initial 3600 + 3600 + 0 + 0 + correction 1800 = 9000
        self::assertSame(9000, $calc->accumulatedBalanceSeconds($c, $days, 1800));
    }
}
