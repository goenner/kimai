<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;

final class WorkBlockMath
{
    /**
     * @param iterable<WorkBlock> $blocks
     */
    public function netSeconds(iterable $blocks, ?\DateTimeImmutable $now = null): int
    {
        $total = 0;
        foreach ($blocks as $block) {
            if (!$block->isOpen()) {
                $total += $block->getDurationSeconds() ?? 0;
            } elseif (null !== $now) {
                $delta = $now->getTimestamp() - $block->getStart()->getTimestamp();
                if ($delta > 0) {
                    $total += $delta;
                }
            }
        }

        return $total;
    }

    /**
     * Sum of the gaps between consecutive closed blocks (the breaks).
     * Expects blocks ordered by start ascending. An open block ends a run
     * (no gap counted after it); overlaps contribute nothing.
     *
     * @param iterable<WorkBlock> $blocks
     */
    public function breakSeconds(iterable $blocks): int
    {
        $gap = 0;
        $prevEnd = null;
        foreach ($blocks as $block) {
            if (null !== $prevEnd) {
                $delta = $block->getStart()->getTimestamp() - $prevEnd;
                if ($delta > 0) {
                    $gap += $delta;
                }
            }
            $prevEnd = $block->getEnd()?->getTimestamp();
        }

        return $gap;
    }
}
