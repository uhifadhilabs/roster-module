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
 * THE FIVE FIGURES THE OVERVIEW LEADS WITH — how many, against how many.
 *
 * EVERY ONE IS A COUNT OF THE AREA'S OWN READING, folded over this module's
 * plan. Not one of them is stored, and not one is this module's opinion: the
 * plan says who was due, the area says what its handsets reported, and these
 * are the sizes of the sets that fall out. A figure with no source does not
 * appear here — an invented denominator is how a dashboard comes to be
 * trusted about something nobody measured.
 *
 * NULL AND ZERO ARE DIFFERENT FACTS. "No post on the books" is not "0% of
 * posts reporting", so a surface asks {@see hasABook()} before it draws a
 * percentage of nothing.
 */
final readonly class DayFigures
{
    public function __construct(
        /** People due on a watch today, across every post on the books. */
        public int $expected,
        /** How many of them the area has any check-in for. */
        public int $checkedIn,
        /** Claims at a post the pings bear out. */
        public int $verified,
        /** Claims at a post the pings do not bear out — shown, never hidden. */
        public int $flagged,
        /** Due, and nothing arrived. */
        public int $noCheckIn,
        /** Posts on the books still talking to us. */
        public int $postsReporting,
        /** Posts on this module's books at all. */
        public int $postsOnTheBooks,
        /** Posts the AREA registers, which is the denominator that is not ours. */
        public int $postsInTheArea,
        /** Watches the pattern asked for that nobody is on, over the window the card reads. */
        public int $holes,
    ) {
    }

    /** Whether this module has any post to report on. */
    public function hasABook(): bool
    {
        return $this->postsOnTheBooks > 0;
    }

    /**
     * WHAT NEEDS SOMEBODY TO ACT, as one number: a missing check-in, a claim
     * the device disagrees with, and a hole are three kinds of problem, and
     * the card that lists them says which. The figure is their total.
     */
    public function needingADecision(): int
    {
        return $this->noCheckIn + $this->flagged + $this->holes;
    }
}
