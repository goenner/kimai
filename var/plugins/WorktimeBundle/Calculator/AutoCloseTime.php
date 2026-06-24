<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

final class AutoCloseTime
{
    private const DEFAULT_HOUR = 17;
    private const DEFAULT_MINUTE = 0;

    /**
     * End time for an open work block that was left running into a past day.
     *
     * The cutoff is the contract daily end time ("HH:MM") if given and valid,
     * otherwise 17:00, anchored to the start's local calendar day in the given
     * timezone. If the block started at/after that cutoff, the end equals the
     * start (zero-length) so it can still be flagged for review.
     */
    public function endFor(\DateTimeImmutable $start, \DateTimeZone $tz, ?string $dailyEndTime): \DateTimeImmutable
    {
        $local = $start->setTimezone($tz);
        [$hour, $minute] = $this->parseTime($dailyEndTime);
        $cutoff = $local->setTime($hour, $minute);

        return $cutoff > $local ? $cutoff : $local;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseTime(?string $time): array
    {
        if (null !== $time && 1 === preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            if ($hour <= 23 && $minute <= 59) {
                return [$hour, $minute];
            }
        }

        return [self::DEFAULT_HOUR, self::DEFAULT_MINUTE];
    }
}
