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

    /**
     * Arbeitszeit der geschlossenen Blöcke je lokalem Kalendertag (Y-m-d), an Mitternacht
     * in $tz aufgeteilt (ein Block 22:00–02:00 zählt 2 h auf jeden der beiden Tage).
     * Offene Blöcke zählen nicht.
     *
     * @param iterable<WorkBlock> $blocks
     *
     * @return array<string, int>
     */
    public function secondsPerLocalDay(iterable $blocks, \DateTimeZone $tz): array
    {
        $result = [];
        foreach ($blocks as $block) {
            $end = $block->getEnd()?->setTimezone($tz);
            if (null === $end) {
                continue;
            }
            $cursor = $block->getStart()->setTimezone($tz);
            while ($cursor < $end) {
                $next = min($end, $cursor->setTime(0, 0)->modify('+1 day'));
                $key = $cursor->format('Y-m-d');
                $result[$key] = ($result[$key] ?? 0) + ($next->getTimestamp() - $cursor->getTimestamp());
                $cursor = $next;
            }
        }

        return $result;
    }
}
