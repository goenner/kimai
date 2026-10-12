<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Validator;

class WorkBlockValidator
{
    public const FUTURE_TOLERANCE_SECONDS = 300;
    public const MAX_DURATION_SECONDS = 86400;

    /**
     * @return string|null null if valid, otherwise a German error message
     */
    public function validateInterval(\DateTimeImmutable $start, ?\DateTimeImmutable $end, ?\DateTimeImmutable $now = null): ?string
    {
        if (null === $end) {
            return null;
        }
        if ($end <= $start) {
            return 'Das Ende muss nach dem Beginn liegen.';
        }
        if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_DURATION_SECONDS) {
            return 'Eine Buchung darf höchstens 24 Stunden dauern.';
        }
        $now ??= new \DateTimeImmutable('now');
        if ($end->getTimestamp() > $now->getTimestamp() + self::FUTURE_TOLERANCE_SECONDS) {
            return 'Das Ende darf nicht in der Zukunft liegen.';
        }

        return null;
    }
}
