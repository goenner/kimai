<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use KimaiPlugin\WorktimeBundle\Calculator\IntervalMath;
use PHPUnit\Framework\TestCase;

class IntervalMathTest extends TestCase
{
    private function d(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    public function testDisjointDoNotOverlap(): void
    {
        $m = new IntervalMath();
        self::assertFalse($m->overlaps($this->d('2026-06-01 08:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 13:00')));
    }

    public function testOverlapping(): void
    {
        $m = new IntervalMath();
        self::assertTrue($m->overlaps($this->d('2026-06-01 08:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 11:00'), $this->d('2026-06-01 13:00')));
    }

    public function testTouchingEdgesDoNotOverlap(): void
    {
        // [08-12) and [12-13): half-open, touching is allowed
        $m = new IntervalMath();
        self::assertFalse($m->overlaps($this->d('2026-06-01 08:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 13:00')));
    }

    public function testContained(): void
    {
        $m = new IntervalMath();
        self::assertTrue($m->overlaps($this->d('2026-06-01 08:00'), $this->d('2026-06-01 16:00'), $this->d('2026-06-01 10:00'), $this->d('2026-06-01 11:00')));
    }

    public function testOpenEndedOverlapsLater(): void
    {
        // open block from 08:00 overlaps a block starting 10:00
        $m = new IntervalMath();
        self::assertTrue($m->overlaps($this->d('2026-06-01 08:00'), null, $this->d('2026-06-01 10:00'), $this->d('2026-06-01 11:00')));
    }
}
