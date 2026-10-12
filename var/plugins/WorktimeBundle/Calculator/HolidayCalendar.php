<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

/**
 * Gesetzliche Feiertage in Deutschland je Bundesland (landesweit geltende Tage).
 *
 * Nur regional geltende Tage (Fronleichnam in Teilen Sachsens/Thüringens, Mariä Himmelfahrt
 * in Teilen Bayerns, Augsburger Friedensfest) und einmalige Feiertage sind bewusst nicht
 * enthalten — dafür gibt es die vom Admin gepflegten freien Tage (FreeDay).
 */
final class HolidayCalendar
{
    public const REGIONS = [
        'BW' => 'Baden-Württemberg',
        'BY' => 'Bayern',
        'BE' => 'Berlin',
        'BB' => 'Brandenburg',
        'HB' => 'Bremen',
        'HH' => 'Hamburg',
        'HE' => 'Hessen',
        'MV' => 'Mecklenburg-Vorpommern',
        'NI' => 'Niedersachsen',
        'NW' => 'Nordrhein-Westfalen',
        'RP' => 'Rheinland-Pfalz',
        'SL' => 'Saarland',
        'SN' => 'Sachsen',
        'ST' => 'Sachsen-Anhalt',
        'SH' => 'Schleswig-Holstein',
        'TH' => 'Thüringen',
    ];

    public const DEFAULT_REGION = 'NW';

    /** Altbestand: Verträge wurden mit Freitext „NRW" angelegt. */
    private const ALIASES = ['NRW' => 'NW'];

    public static function normalizeRegion(?string $region): string
    {
        $code = strtoupper(trim((string) $region));
        $code = self::ALIASES[$code] ?? $code;

        return isset(self::REGIONS[$code]) ? $code : self::DEFAULT_REGION;
    }

    /**
     * @return array<string, string> Y-m-d => Name, chronologisch sortiert
     */
    public function holidays(int $year, string $region): array
    {
        $region = self::normalizeRegion($region);
        $easter = (new \DateTimeImmutable(\sprintf('%04d-03-21', $year)))->modify('+'.easter_days($year).' days');
        $e = static fn (int $offset): string => $easter->modify(\sprintf('%+d days', $offset))->format('Y-m-d');
        $d = static fn (int $month, int $day): string => \sprintf('%04d-%02d-%02d', $year, $month, $day);
        $in = static fn (string ...$codes): bool => \in_array($region, $codes, true);

        $h = [
            $d(1, 1) => 'Neujahr',
            $e(-2) => 'Karfreitag',
            $e(1) => 'Ostermontag',
            $d(5, 1) => 'Tag der Arbeit',
            $e(39) => 'Christi Himmelfahrt',
            $e(50) => 'Pfingstmontag',
            $d(10, 3) => 'Tag der Deutschen Einheit',
            $d(12, 25) => '1. Weihnachtstag',
            $d(12, 26) => '2. Weihnachtstag',
        ];

        if ($in('BW', 'BY', 'ST')) {
            $h[$d(1, 6)] = 'Heilige Drei Könige';
        }
        if (($in('BE') && $year >= 2019) || ($in('MV') && $year >= 2023)) {
            $h[$d(3, 8)] = 'Internationaler Frauentag';
        }
        if ($in('BB')) {
            $h[$e(0)] = 'Ostersonntag';
            $h[$e(49)] = 'Pfingstsonntag';
        }
        if ($in('BW', 'BY', 'HE', 'NW', 'RP', 'SL')) {
            $h[$e(60)] = 'Fronleichnam';
        }
        if ($in('SL')) {
            $h[$d(8, 15)] = 'Mariä Himmelfahrt';
        }
        if ($in('TH') && $year >= 2019) {
            $h[$d(9, 20)] = 'Weltkindertag';
        }
        // 2017: 500 Jahre Reformation, einmalig bundesweit
        if ($in('BB', 'MV', 'SN', 'ST', 'TH') || ($in('HB', 'HH', 'NI', 'SH') && $year >= 2018) || 2017 === $year) {
            $h[$d(10, 31)] = 'Reformationstag';
        }
        if ($in('BW', 'BY', 'NW', 'RP', 'SL')) {
            $h[$d(11, 1)] = 'Allerheiligen';
        }
        if ($in('SN')) {
            // Mittwoch vor dem 23. November
            $h[(new \DateTimeImmutable($d(11, 23)))->modify('last wednesday')->format('Y-m-d')] = 'Buß- und Bettag';
        }

        ksort($h);

        return $h;
    }
}
