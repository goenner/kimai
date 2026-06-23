<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Validator;

use KimaiPlugin\WorktimeBundle\Validator\WorkBlockValidator;
use PHPUnit\Framework\TestCase;

class WorkBlockValidatorTest extends TestCase
{
    private function v(): WorkBlockValidator
    {
        return new WorkBlockValidator();
    }

    public function testEndAfterStartIsValid(): void
    {
        $start = new \DateTimeImmutable('2026-06-01 08:00:00');
        $end = new \DateTimeImmutable('2026-06-01 12:00:00');
        self::assertNull($this->v()->validateInterval($start, $end));
    }

    public function testOpenIntervalIsValid(): void
    {
        $start = new \DateTimeImmutable('2026-06-01 08:00:00');
        self::assertNull($this->v()->validateInterval($start, null));
    }

    public function testEndBeforeStartIsInvalid(): void
    {
        $start = new \DateTimeImmutable('2026-06-01 12:00:00');
        $end = new \DateTimeImmutable('2026-06-01 08:00:00');
        self::assertNotNull($this->v()->validateInterval($start, $end));
    }

    public function testEndEqualStartIsInvalid(): void
    {
        $start = new \DateTimeImmutable('2026-06-01 08:00:00');
        $end = new \DateTimeImmutable('2026-06-01 08:00:00');
        self::assertNotNull($this->v()->validateInterval($start, $end));
    }
}
