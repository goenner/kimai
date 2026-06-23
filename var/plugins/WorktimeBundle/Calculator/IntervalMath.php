<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

final class IntervalMath
{
    /**
     * Half-open interval overlap: [aStart, aEnd) vs [bStart, bEnd).
     * A null end means open-ended (treated as +infinity), so touching edges
     * do NOT count as overlap.
     */
    public function overlaps(\DateTimeInterface $aStart, ?\DateTimeInterface $aEnd, \DateTimeInterface $bStart, ?\DateTimeInterface $bEnd): bool
    {
        $aStartTs = $aStart->getTimestamp();
        $bStartTs = $bStart->getTimestamp();
        $aEndTs = $aEnd?->getTimestamp() ?? PHP_INT_MAX;
        $bEndTs = $bEnd?->getTimestamp() ?? PHP_INT_MAX;

        return $aStartTs < $bEndTs && $bStartTs < $aEndTs;
    }
}
