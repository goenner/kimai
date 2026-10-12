<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Validator;

use KimaiPlugin\WorktimeBundle\Validator\DateInput;
use PHPUnit\Framework\TestCase;

class DateInputTest extends TestCase
{
    public function testParsesValidDateAtMidnight(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $d = DateInput::parse('2026-02-28', $tz);
        self::assertNotNull($d);
        self::assertSame('2026-02-28 00:00:00 Europe/Berlin', $d->format('Y-m-d H:i:s e'));
    }

    public function testRejectsOverflowAndGarbage(): void
    {
        $tz = new \DateTimeZone('UTC');
        self::assertNull(DateInput::parse('2026-02-31', $tz));
        self::assertNull(DateInput::parse('2026-13-01', $tz));
        self::assertNull(DateInput::parse('', $tz));
        self::assertNull(DateInput::parse('gestern', $tz));
        self::assertNull(DateInput::parse('2026-06-01T25:00', $tz, ['Y-m-d\TH:i:s', 'Y-m-d\TH:i']));
    }

    public function testParsesDateTimeWithAndWithoutSeconds(): void
    {
        $tz = new \DateTimeZone('UTC');
        $formats = ['Y-m-d\TH:i:s', 'Y-m-d\TH:i'];
        self::assertSame('2026-06-01 08:30:00', DateInput::parse('2026-06-01T08:30', $tz, $formats)?->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-01 08:30:15', DateInput::parse('2026-06-01T08:30:15', $tz, $formats)?->format('Y-m-d H:i:s'));
    }

    public function testYearAndMonthAreClamped(): void
    {
        self::assertSame(2026, DateInput::year('2026', 2020));
        self::assertSame(2020, DateInput::year('1999', 2020));
        self::assertSame(2020, DateInput::year('2101', 2020));
        self::assertSame(2020, DateInput::year('20x6', 2020));
        self::assertSame(2020, DateInput::year(null, 2020));
        self::assertSame(12, DateInput::month('12', 5));
        self::assertSame(5, DateInput::month('0', 5));
        self::assertSame(5, DateInput::month('13', 5));
    }
}
