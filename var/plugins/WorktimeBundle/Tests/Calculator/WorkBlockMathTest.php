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
}
