<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\WorkBlockMath;
use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;
use PHPUnit\Framework\TestCase;

class WorkBlockMathTest extends TestCase
{
    private function block(string $start, ?string $end): WorkBlock
    {
        $b = new WorkBlock();
        $b->setUser(new User());
        $b->setStart(new \DateTimeImmutable($start));
        $b->setEnd(null === $end ? null : new \DateTimeImmutable($end));

        return $b;
    }

    public function testEmptyIsZero(): void
    {
        self::assertSame(0, (new WorkBlockMath())->netSeconds([]));
    }

    public function testSumsClosedBlocks(): void
    {
        $blocks = [
            $this->block('2026-06-01 08:00:00', '2026-06-01 12:00:00'), // 4h
            $this->block('2026-06-01 12:30:00', '2026-06-01 16:30:00'), // 4h
        ];
        self::assertSame(28800, (new WorkBlockMath())->netSeconds($blocks)); // 8h
    }

    public function testOpenBlockIgnoredWithoutNow(): void
    {
        $blocks = [$this->block('2026-06-01 08:00:00', null)];
        self::assertSame(0, (new WorkBlockMath())->netSeconds($blocks));
    }

    public function testOpenBlockCountedUpToNow(): void
    {
        $blocks = [$this->block('2026-06-01 08:00:00', null)];
        $now = new \DateTimeImmutable('2026-06-01 09:30:00');
        self::assertSame(5400, (new WorkBlockMath())->netSeconds($blocks, $now)); // 1.5h
    }

    public function testMixedClosedAndOpen(): void
    {
        $blocks = [
            $this->block('2026-06-01 08:00:00', '2026-06-01 12:00:00'), // 4h
            $this->block('2026-06-01 13:00:00', null),                   // open
        ];
        $now = new \DateTimeImmutable('2026-06-01 14:00:00');            // +1h
        self::assertSame(18000, (new WorkBlockMath())->netSeconds($blocks, $now)); // 5h
    }

    public function testBreakEmptyIsZero(): void
    {
        self::assertSame(0, (new WorkBlockMath())->breakSeconds([]));
    }

    public function testBreakSingleBlockIsZero(): void
    {
        $blocks = [$this->block('2026-06-01 08:00:00', '2026-06-01 12:00:00')];
        self::assertSame(0, (new WorkBlockMath())->breakSeconds($blocks));
    }

    public function testBreakBetweenTwoBlocks(): void
    {
        $blocks = [
            $this->block('2026-06-01 08:00:00', '2026-06-01 12:00:00'),
            $this->block('2026-06-01 12:30:00', '2026-06-01 16:30:00'),
        ];
        self::assertSame(1800, (new WorkBlockMath())->breakSeconds($blocks)); // 30 min
    }

    public function testBreakSumsMultipleGaps(): void
    {
        $blocks = [
            $this->block('2026-06-01 08:00:00', '2026-06-01 10:00:00'),
            $this->block('2026-06-01 10:30:00', '2026-06-01 12:00:00'), // 30 min gap
            $this->block('2026-06-01 13:00:00', '2026-06-01 15:00:00'), // 60 min gap
        ];
        self::assertSame(5400, (new WorkBlockMath())->breakSeconds($blocks)); // 90 min
    }

    public function testBreakBeforeOpenBlock(): void
    {
        $blocks = [
            $this->block('2026-06-01 08:00:00', '2026-06-01 12:00:00'),
            $this->block('2026-06-01 13:00:00', null), // running; 60 min gap before it
        ];
        self::assertSame(3600, (new WorkBlockMath())->breakSeconds($blocks));
    }

    public function testBreakIgnoresOverlap(): void
    {
        $blocks = [
            $this->block('2026-06-01 08:00:00', '2026-06-01 12:00:00'),
            $this->block('2026-06-01 11:00:00', '2026-06-01 13:00:00'), // overlaps -> negative gap ignored
        ];
        self::assertSame(0, (new WorkBlockMath())->breakSeconds($blocks));
    }

    public function testSecondsPerLocalDaySplitsAtLocalMidnight(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        // 22:00–02:00 Berlin (CEST) als UTC-Instants
        $b = new WorkBlock();
        $b->setUser(new User());
        $b->setStart(new \DateTimeImmutable('2026-06-01 20:00:00', new \DateTimeZone('UTC')));
        $b->setEnd(new \DateTimeImmutable('2026-06-02 00:00:00', new \DateTimeZone('UTC')));

        self::assertSame(['2026-06-01' => 7200, '2026-06-02' => 7200], (new WorkBlockMath())->secondsPerLocalDay([$b], $tz));
    }

    public function testSecondsPerLocalDaySumsAndIgnoresOpenBlocks(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        $blocks = [
            $this->block('2026-06-01 08:00:00 Europe/Berlin', '2026-06-01 12:00:00 Europe/Berlin'),
            $this->block('2026-06-01 13:00:00 Europe/Berlin', '2026-06-01 14:00:00 Europe/Berlin'),
            $this->block('2026-06-02 08:00:00 Europe/Berlin', null),
        ];
        self::assertSame(['2026-06-01' => 18000], (new WorkBlockMath())->secondsPerLocalDay($blocks, $tz));
    }

    public function testSecondsPerLocalDayAcrossDstChangeUsesRealDuration(): void
    {
        // Nacht der Zeitumstellung 25.10.2026: 22:00–06:00 lokal = 9 h real
        $tz = new \DateTimeZone('Europe/Berlin');
        $b = $this->block('2026-10-24 22:00:00 Europe/Berlin', '2026-10-25 06:00:00 Europe/Berlin');
        self::assertSame(['2026-10-24' => 7200, '2026-10-25' => 25200], (new WorkBlockMath())->secondsPerLocalDay([$b], $tz));
    }
}
