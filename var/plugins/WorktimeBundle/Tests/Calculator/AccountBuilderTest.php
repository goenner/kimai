<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\AccountBuilder;
use KimaiPlugin\WorktimeBundle\Calculator\WorktimeCalculator;
use KimaiPlugin\WorktimeBundle\Entity\Absence;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use PHPUnit\Framework\TestCase;

class AccountBuilderTest extends TestCase
{
    private const H8 = 28800;

    private function contract(): Contract
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

    private function builder(): AccountBuilder
    {
        return new AccountBuilder(new WorktimeCalculator());
    }

    private function vacation(string $from, string $to, bool $half): Absence
    {
        $a = new Absence(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $a->setUser(new User());
        $a->setStartDate(new \DateTimeImmutable($from));
        $a->setEndDate(new \DateTimeImmutable($to));
        $a->setHalfDay($half);

        return $a;
    }

    public function testWorkdayExactlyMet(): void
    {
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), self::H8, []);
        self::assertSame(self::H8, $d->targetSeconds);
        self::assertSame(self::H8, $d->workedSeconds);
        self::assertSame(0, $d->creditSeconds);
        self::assertSame(0, $d->balanceSeconds);
        self::assertNull($d->note);
    }

    public function testOvertime(): void
    {
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), 32400, []);
        self::assertSame(3600, $d->balanceSeconds);
    }

    public function testWeekendNoTargetNoWork(): void
    {
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-06'), 0, []);
        self::assertSame(0, $d->targetSeconds);
        self::assertSame(0, $d->balanceSeconds);
    }

    public function testFullVacationIsNeutral(): void
    {
        $abs = [$this->vacation('2026-06-01', '2026-06-05', false)];
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), 0, $abs);
        self::assertSame(self::H8, $d->creditSeconds);
        self::assertSame(0, $d->balanceSeconds);
        self::assertSame('Urlaub', $d->note);
    }

    public function testHalfVacationCreditsHalfTarget(): void
    {
        $abs = [$this->vacation('2026-06-01', '2026-06-01', true)];
        // worked the other half (4h) -> balance 0
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), 14400, $abs);
        self::assertSame(14400, $d->creditSeconds);
        self::assertSame(0, $d->balanceSeconds);
        self::assertSame('½ Urlaub', $d->note);
    }

    public function testAbsenceOutsideRangeIgnored(): void
    {
        $abs = [$this->vacation('2026-07-01', '2026-07-05', false)];
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), self::H8, $abs);
        self::assertSame(0, $d->creditSeconds);
        self::assertNull($d->note);
    }
}
