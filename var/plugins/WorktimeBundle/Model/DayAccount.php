<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Model;

final class DayAccount
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly int $targetSeconds,
        public readonly int $workedSeconds,
        public readonly int $creditSeconds,
        public readonly int $balanceSeconds,
        public readonly ?string $note,
    ) {
    }
}
