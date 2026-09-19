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
 * WHAT IS TRUE THIS MINUTE, in five figures.
 *
 * EVERY ONE IS ABOUT AN INSTANT, which is what separates this strip from
 * every other in the module: the agenda's figures settle once the day is
 * over, and these are different a minute later. That is why the answer they
 * are folded from carries its own `asOf` rather than reading a clock.
 *
 * "OF EXPECTED" IS THE ROSTER'S DENOMINATOR AND THE POSITIONS ARE THE
 * AREA'S. Eleven of thirteen is two people whose phones have said nothing —
 * not two people missing, which is a different accusation and one this
 * module is careful never to make.
 */
final readonly class LiveFigures
{
    public function __construct(
        /** Live fixes the area has, this minute. */
        public int $positions,
        /** People the roster has on a watch. */
        public int $expected,
        /** Of those fixes, how many the area calls stale. */
        public int $stale,
        /** Rostered people with no fix at all. */
        public int $withoutAFix,
        /** Fixes the pings bear out at the post claimed. */
        public int $verified,
        /** Fixes that are not a claim to be at a post. */
        public int $awayFromAPost,
        /** The oldest fix, in the words a person uses, or null where there is none. */
        public ?string $oldestAge,
        public ?string $oldestName,
        public ?string $oldestStation,
        /** What the area expects a handset to ping at. */
        public int $pingIntervalMinutes,
        public int $postsReporting,
        public int $postsOnTheBooks,
    ) {
    }

    /** Whether there is any live answer at all to draw. */
    public function hasPositions(): bool
    {
        return $this->positions > 0;
    }
}
