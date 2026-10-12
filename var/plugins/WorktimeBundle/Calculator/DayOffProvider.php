<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

use KimaiPlugin\WorktimeBundle\Entity\FreeDay;
use KimaiPlugin\WorktimeBundle\Repository\FreeDayRepository;

/**
 * Freie Tage eines Bundeslandes: gesetzliche Feiertage + vom Admin eingetragene ganze/halbe Tage.
 * Ein gesetzlicher Feiertag schlägt immer einen halben freien Tag am selben Datum.
 */
class DayOffProvider
{
    public function __construct(
        private readonly HolidayCalendar $calendar,
        private readonly FreeDayRepository $freeDays,
    ) {
    }

    /**
     * @return array<string, array{name: string, half: bool}>
     */
    public function between(string $region, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return self::merge($this->calendar, $region, $from, $to, $this->freeDays->findBetween($from, $to));
    }

    /**
     * @param FreeDay[] $freeDays
     * @return array<string, array{name: string, half: bool}>
     */
    public static function merge(HolidayCalendar $calendar, string $region, \DateTimeInterface $from, \DateTimeInterface $to, array $freeDays): array
    {
        $region = HolidayCalendar::normalizeRegion($region);
        $fromKey = $from->format('Y-m-d');
        $toKey = $to->format('Y-m-d');
        $result = [];

        foreach ($freeDays as $free) {
            if (null !== $free->getRegion() && $free->getRegion() !== $region) {
                continue;
            }
            $key = $free->getDay()->format('Y-m-d');
            // Zwei Einträge am selben Tag: der ganze Tag gewinnt
            if (!isset($result[$key]) || $result[$key]['half']) {
                $result[$key] = ['name' => $free->getName(), 'half' => $free->isHalfDay()];
            }
        }

        for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); ++$year) {
            foreach ($calendar->holidays($year, $region) as $key => $name) {
                if ($key >= $fromKey && $key <= $toKey) {
                    $result[$key] = ['name' => $name, 'half' => false];
                }
            }
        }

        ksort($result);

        return $result;
    }
}
