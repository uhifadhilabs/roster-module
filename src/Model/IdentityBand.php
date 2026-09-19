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
 * STRUCTURAL, AND THAT IS THE WHOLE RULE. How many rangers are on the books,
 * how many posts run a watch, how many rotations, how big the shift
 * vocabulary is, how often a handset pings, how far the generator has run.
 * Nothing on it is LIVE — how many are on duty right now is a KPI and belongs
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
