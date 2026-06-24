<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use KimaiPlugin\WorktimeBundle\Calculator\AutoCloseTime;
use PHPUnit\Framework\TestCase;

class AutoCloseTimeTest extends TestCase
{
    private function berlin(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s, new \DateTimeZone('Europe/Berlin'));
    }

    private function calc(): AutoCloseTime
    {
        return new AutoCloseTime();
    }

    public function testDefaultsToFivePmWhenNoContractTime(): void
    {
        $end = $this->calc()->endFor($this->berlin('2026-06-01 08:00'), new \DateTimeZone('Europe/Berlin'), null);
        self::assertSame('2026-06-01 17:00', $end->format('Y-m-d H:i'));
    }

    public function testUsesContractDailyEndTime(): void
    {
        $end = $this->calc()->endFor($this->berlin('2026-06-01 08:00'), new \DateTimeZone('Europe/Berlin'), '16:30');
        self::assertSame('2026-06-01 16:30', $end->format('Y-m-d H:i'));
    }

    public function testStartAfterCutoffReturnsStart(): void
    {
        $start = $this->berlin('2026-06-01 19:00');
        $end = $this->calc()->endFor($start, new \DateTimeZone('Europe/Berlin'), null);
        self::assertSame($start->getTimestamp(), $end->getTimestamp());
    }

    public function testInvalidDailyEndTimeFallsBackToFivePm(): void
    {
        $end = $this->calc()->endFor($this->berlin('2026-06-01 08:00'), new \DateTimeZone('Europe/Berlin'), 'garbage');
        self::assertSame('2026-06-01 17:00', $end->format('Y-m-d H:i'));
    }

    public function testAnchorsCutoffToStartLocalDayAcrossTimezone(): void
    {
        // 06:00 UTC = 08:00 Berlin -> cutoff is 17:00 Berlin on the same local day
        $start = new \DateTimeImmutable('2026-06-01 06:00', new \DateTimeZone('UTC'));
        $end = $this->calc()->endFor($start, new \DateTimeZone('Europe/Berlin'), null);
        self::assertSame('2026-06-01 17:00', $end->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i'));
    }
}
