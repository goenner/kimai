<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Validator;

/**
 * Strikte Auswertung von Datums- und Zeitraum-Eingaben aus Formularen und Query-Parametern.
 */
final class DateInput
{
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2100;
    public const NOTE_MAX_LENGTH = 500;

    /**
     * Wie createFromFormat(), weist aber Überläufe (2026-02-31, 25:00) ab. Unbelegte Felder sind 0.
     *
     * @param string[] $formats
     */
    public static function parse(string $raw, \DateTimeZone $tz, array $formats = ['Y-m-d']): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!'.$format, $raw, $tz);
            if ($dt instanceof \DateTimeImmutable && $dt->format($format) === $raw) {
                return $dt;
            }
        }

        return null;
    }

    /**
     * Jahr aus einem Query-Parameter; außerhalb 2000–2100 oder ungültig: $fallback.
     */
    public static function year(mixed $raw, int $fallback): int
    {
        $year = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::MIN_YEAR, 'max_range' => self::MAX_YEAR]]);

        return false === $year ? $fallback : $year;
    }

    /**
     * Monat aus einem Query-Parameter; außerhalb 1–12 oder ungültig: $fallback.
     */
    public static function month(mixed $raw, int $fallback): int
    {
        $month = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);

        return false === $month ? $fallback : $month;
    }
}
