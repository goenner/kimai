<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\AccountBuilder;
use KimaiPlugin\WorktimeBundle\Calculator\DayOffProvider;
use KimaiPlugin\WorktimeBundle\Calculator\HolidayCalendar;
use KimaiPlugin\WorktimeBundle\Calculator\VacationCalculator;
use KimaiPlugin\WorktimeBundle\Calculator\WorktimeCalculator;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Entity\FreeDay;
use PHPUnit\Framework\TestCase;

class HolidayCalendarTest extends TestCase
{
    private const H8 = 28800;

    private function contract(string $region = 'NW'): Contract
    {
        $c = new Contract();
        $c->setUser(new User());
        foreach ([1, 2, 3, 4, 5] as $wd) {
            $c->setWorkHoursForWeekday($wd, self::H8);
        }
        $c->setHolidayRegion($region);

        return $c;
    }

    public function testNrw2026(): void
    {
        $h = (new HolidayCalendar())->holidays(2026, 'NW');
        // Ostersonntag 2026 = 5. April
        self::assertSame([
            '2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-05-14',
            '2026-05-25', '2026-06-04', '2026-10-03', '2026-11-01', '2026-12-25', '2026-12-26',
        ], array_keys($h));
        self::assertSame('Fronleichnam', $h['2026-06-04']);
    }

    public function testLegacyFreeTextRegionIsNrw(): void
    {
        self::assertSame('NW', HolidayCalendar::normalizeRegion('NRW'));
        self::assertSame('NW', HolidayCalendar::normalizeRegion(' nrw '));
        self::assertSame('NW', HolidayCalendar::normalizeRegion(''));
        self::assertSame('BY', HolidayCalendar::normalizeRegion('by'));
    }

    public function testRegionSpecificDays(): void
    {
        $cal = new HolidayCalendar();
        self::assertArrayHasKey('2026-11-18', $cal->holidays(2026, 'SN'), 'Buß- und Bettag 2026');
        self::assertArrayHasKey('2025-11-19', $cal->holidays(2025, 'SN'), 'Buß- und Bettag 2025');
        self::assertArrayHasKey('2026-10-31', $cal->holidays(2026, 'NI'));
        self::assertArrayHasKey('2017-10-31', $cal->holidays(2017, 'NW'), '500 Jahre Reformation: 2017 bundesweit');
        self::assertArrayNotHasKey('2016-10-31', $cal->holidays(2016, 'NI'));
        self::assertArrayHasKey('2026-03-08', $cal->holidays(2026, 'BE'));
        self::assertArrayNotHasKey('2026-03-08', $cal->holidays(2026, 'NW'));
        self::assertArrayHasKey('2026-09-20', $cal->holidays(2026, 'TH'));
        self::assertArrayHasKey('2026-08-15', $cal->holidays(2026, 'SL'));
        self::assertArrayHasKey('2026-01-06', $cal->holidays(2026, 'BY'));
        self::assertCount(10, $cal->holidays(2026, 'HH'));
    }

    public function testMergeFreeDaysAndRegions(): void
    {
        $free = [
            new FreeDay(new \DateTimeImmutable('2026-12-24'), 'Heiligabend', true),
            new FreeDay(new \DateTimeImmutable('2026-12-31'), 'Silvester', true),
            new FreeDay(new \DateTimeImmutable('2026-05-15'), 'Brückentag', false, 'NW'),
            new FreeDay(new \DateTimeImmutable('2026-05-15'), 'Nur Bayern', false, 'BY'),
            new FreeDay(new \DateTimeImmutable('2026-12-25'), 'halber Tag am Feiertag', true),
        ];
        $m = DayOffProvider::merge(new HolidayCalendar(), 'NRW', new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-12-31'), $free);
        self::assertSame(['name' => 'Heiligabend', 'half' => true], $m['2026-12-24']);
        self::assertSame(['name' => 'Brückentag', 'half' => false], $m['2026-05-15']);
        self::assertSame(['name' => '1. Weihnachtstag', 'half' => false], $m['2026-12-25'], 'Feiertag schlägt halben freien Tag');
        self::assertArrayNotHasKey('2026-01-01', $m, 'außerhalb des Zeitraums');
    }

    public function testVacationSkipsHolidaysAndHalvesHalfDays(): void
    {
        $calc = new VacationCalculator();
        $off = DayOffProvider::merge(new HolidayCalendar(), 'NW', new \DateTimeImmutable('2026-12-21'), new \DateTimeImmutable('2027-01-08'), [
            new FreeDay(new \DateTimeImmutable('2026-12-24'), 'Heiligabend', true),
            new FreeDay(new \DateTimeImmutable('2026-12-31'), 'Silvester', true),
        ]);
        // Mo 21.12. – Do 31.12.2026: 9 Werktage, davon 25.12. Feiertag, 24.+31. halb → 6 + 0,5 + 0,5 = 7
        self::assertSame(7.0, $calc->countDays($this->contract(), new \DateTimeImmutable('2026-12-21'), new \DateTimeImmutable('2026-12-31'), false, $off));
        self::assertSame(0.5, $calc->countDays($this->contract(), new \DateTimeImmutable('2026-12-24'), new \DateTimeImmutable('2026-12-24'), true, $off));
        self::assertSame(0.0, $calc->countDays($this->contract(), new \DateTimeImmutable('2026-12-25'), new \DateTimeImmutable('2026-12-25'), true, $off));
        // ohne Feiertagsliste: altes Verhalten
        self::assertSame(9.0, $calc->countDays($this->contract(), new \DateTimeImmutable('2026-12-21'), new \DateTimeImmutable('2026-12-31'), false));
    }

    public function testAccountTargetOnHolidayAndHalfDay(): void
    {
        $b = new AccountBuilder(new WorktimeCalculator());
        $c = $this->contract();

        $holiday = $b->dayAccount($c, new \DateTimeImmutable('2026-12-25'), 0, [], ['name' => '1. Weihnachtstag', 'half' => false]);
        self::assertSame(0, $holiday->targetSeconds);
        self::assertSame(0, $holiday->balanceSeconds);
        self::assertSame('1. Weihnachtstag', $holiday->note);

        $half = $b->dayAccount($c, new \DateTimeImmutable('2026-12-24'), 4 * 3600, [], ['name' => 'Heiligabend', 'half' => true]);
        self::assertSame(4 * 3600, $half->targetSeconds);
        self::assertSame(0, $half->balanceSeconds);
        self::assertSame('½ Heiligabend', $half->note);

        $worked = $b->dayAccount($c, new \DateTimeImmutable('2026-12-25'), 2 * 3600, [], ['name' => '1. Weihnachtstag', 'half' => false]);
        self::assertSame(2 * 3600, $worked->balanceSeconds, 'Arbeit am Feiertag ist komplett Plus');
    }
}
