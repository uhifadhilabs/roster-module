<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Roster Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Model;

/**
 * ONE POST'S ROW ACROSS THE WEEK.
 *
 * A DAY WITH NO CELLS IS NOT AN EMPTY CELL. A post the ring stands down, and
 * a post that declares no watch at all, are both "nothing expected here" —
 * and the grid says so in words rather than leaving a blank, because a blank
 * reads as "not loaded yet". Which of the two it is, is the row's to know:
 * {@see $runsNoWatch} is the post that declares none, and a day missing from
 * {@see $cells} on a post that DOES run a watch is a stand-down.
 */
final readonly class WeekRow
{
    /**
     * @param array<string, list<WeekCell>> $cells Y-m-d => the watches that day, in shift order
     */
    public function __construct(
        public string $stationUuid,
        public string $stationName,
        public ?string $stationCode,
        public array $cells,
        public bool $runsNoWatch,
    ) {
    }

    /** @return list<WeekCell> */
    public function on(\DateTimeImmutable $day): array
    {
        return $this->cells[$day->format('Y-m-d')] ?? [];
    }

    public function shortfallOn(\DateTimeImmutable $day): int
    {
        $short = 0;
        foreach ($this->on($day) as $cell) {
            $short += $cell->shortfall();
        }

        return $short;
    }
}
