<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use KimaiPlugin\WorktimeBundle\Calculator\DateRangeMath;
use PHPUnit\Framework\TestCase;

class DateRangeMathTest extends TestCase
{
    private function d(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    private function math(): DateRangeMath
    {
        return new DateRangeMath();
    }

    public function testDisjointRangesDoNotOverlap(): void
    {
        self::assertFalse($this->math()->overlaps($this->d('2026-06-01'), $this->d('2026-06-05'), $this->d('2026-06-06'), $this->d('2026-06-10')));
    }

    public function testTouchingSameDayOverlaps(): void
    {
        // inclusive dates: 05 is shared
        self::assertTrue($this->math()->overlaps($this->d('2026-06-01'), $this->d('2026-06-05'), $this->d('2026-06-05'), $this->d('2026-06-10')));
    }

    public function testContainedOverlaps(): void
    {
        self::assertTrue($this->math()->overlaps($this->d('2026-06-01'), $this->d('2026-06-30'), $this->d('2026-06-10'), $this->d('2026-06-11')));
    }

    public function testSingleDayInsideRangeOverlaps(): void
    {
        self::assertTrue($this->math()->overlaps($this->d('2026-06-10'), $this->d('2026-06-10'), $this->d('2026-06-01'), $this->d('2026-06-30')));
    }

    public function testIgnoresTimeComponent(): void
    {
        self::assertTrue($this->math()->overlaps($this->d('2026-06-05 23:30'), $this->d('2026-06-05 08:00'), $this->d('2026-06-05 09:00'), $this->d('2026-06-05 10:00')));
    }

    public function testAdjacentDaysDoNotOverlap(): void
    {
        self::assertFalse($this->math()->overlaps($this->d('2026-06-05'), $this->d('2026-06-05'), $this->d('2026-06-06'), $this->d('2026-06-06')));
    }
}
