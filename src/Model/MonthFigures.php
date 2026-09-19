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
 * ONE RANGER'S MONTH, IN FIGURES — what the calendar's strip says above the
 * grid that shows it day by day.
 *
 * IT IS ABOUT A PERSON, not about the area, and that is the whole difference
 * from the other strips in this module. "Unverified 2" here means twice this
 * month somebody's own claim was not borne out; the same word on the agenda
 * means two people this morning.
 *
 * WORKED IS NOT ROSTERED. A month has watches the plan asked for and watches
 * that were actually reported, and the verified figure is a share of the
 * SECOND — dividing by the plan would score somebody down for a day they were
 * never on.
 *
 * THE LONGEST RUN OF NIGHTS IS THE ONE FIGURE THAT IS NOT A COUNT. It is what
 * a rest rule is argued about, and a total of nights hides it: seven nights
 * spread through a month and seven in a row are not the same month.
 */
final readonly class MonthFigures
{
    public function __construct(
        /** Watches the plan put this person on, this month. */
        public int $watches,
        /** Days in the month, which is the denominator a person reads. */
        public int $daysInMonth,
        /** Of those watches, how many are on a shift that crosses midnight. */
        public int $nights,
        /** And how many are not. */
        public int $days,
        /** Past watches the area read anything at all for. */
        public int $worked,
        /** Of those, how many the pings bore out. */
        public int $verified,
        /** Claims at a post the pings did not bear out. */
        public int $unverified,
        /** Past watches nothing arrived for. */
        public int $noCheckIn,
        /** The longest run of consecutive nights in the month. */
        public int $longestNightRun,
    ) {
    }

    /** Whether there is anything to say about this month at all. */
    public function hasAMonth(): bool
    {
        return $this->watches > 0;
    }
}
