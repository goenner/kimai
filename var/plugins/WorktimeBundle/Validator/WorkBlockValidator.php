<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Validator;

class WorkBlockValidator
{
    /**
     * @return string|null null if valid, otherwise a German error message
     */
    public function validateInterval(\DateTimeImmutable $start, ?\DateTimeImmutable $end): ?string
    {
        if (null !== $end && $end <= $start) {
            return 'Das Ende muss nach dem Beginn liegen.';
        }

        return null;
    }
}
