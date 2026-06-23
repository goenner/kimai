<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Model;

final class DayFacts
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly int $workedSeconds = 0,
        public readonly bool $publicHoliday = false,
        public readonly int $absenceCreditSeconds = 0,
        public readonly int $overtimeReductionSeconds = 0,
    ) {
    }
}
