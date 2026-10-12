<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

final class DateRangeMath
{
    /**
     * Inclusive date-range overlap: [aStart, aEnd] vs [bStart, bEnd], compared by
     * calendar day (time component ignored). Sharing a single day counts as overlap.
     */
    public function overlaps(\DateTimeInterface $aStart, \DateTimeInterface $aEnd, \DateTimeInterface $bStart, \DateTimeInterface $bEnd): bool
    {
        $aFrom = $aStart->format('Y-m-d');
        $aTo = $aEnd->format('Y-m-d');
        $bFrom = $bStart->format('Y-m-d');
        $bTo = $bEnd->format('Y-m-d');

        // normalise reversed ranges defensively
        if ($aFrom > $aTo) {
            [$aFrom, $aTo] = [$aTo, $aFrom];
        }
        if ($bFrom > $bTo) {
            [$bFrom, $bTo] = [$bTo, $bFrom];
        }

        return $aFrom <= $bTo && $bFrom <= $aTo;
    }
}
