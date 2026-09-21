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
 * THE MODULE'S IDENTITY BAND — the slim strip of STRUCTURAL FACTS that stands
 * under the tab strip and is byte-identical on every tab and on Configure.
 *
 * STRUCTURAL, AND THAT IS THE WHOLE RULE. How many stations the area
 * registers and how many stand a shift, how many rangers are stationed at
 * them, how many shifts this area has named, how many stations run on
 * their own rules. Nothing on it is LIVE — how many are on duty right now is a KPI and belongs
 * in the figures row, and putting a live number on a band that repeats on six
 * tabs would mean six different readings of the same minute.
 *
 * Its one link is Roster settings.
 */
final readonly class IdentityBand
{
    public function __construct(
        /** People the area posts to the stations this module works. */
        public int $rangers,
        /** How many of the area's stations run a watch. */
        public int $postsWithAWatch,
        /**
         * HOW MANY STAND AT LEAST ONE SHIFT — the band's own figure, and not
         * the same as the one above it: a station is put on these books
         * deliberately and is then told what it stands, so one that has
         * joined and been told nothing is on the books and stands nothing.
         */
        public int $stationsRunningAShift,
        /**
         * HOW MANY STATIONS RUN ON SOMETHING OTHER THAN THE AREA'S RULES —
         * stations, not exception rows: one station with three of its own is
         * one station to go and look at.
         */
        public int $stationsWithTheirOwnRules,
        /** How many stations the area registers, watch or no watch. */
        public int $stationsInArea,
        public int $rotations,
        public int $rotationsPerPost,
        public int $rotationsPerTeam,
        /** How many named windows the area's list holds, closed ones excluded. */
        public int $namedShifts,
        /** The labels behind that number, in list order — "day, night, office, radio". */
        public string $shiftLabels,
        public int $pingIntervalMinutes,
        /**
         * HOW FAR THE PLAN RUNS — the furthest any standing rotation has
         * generated to, or null before the first run. Null is not zero: "no
         * rotation has run yet" and "generated to today" are different facts
         * and the band draws them differently.
         */
        public ?\DateTimeImmutable $generatedThrough,
    ) {
    }
}
