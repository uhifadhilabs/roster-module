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
 * THE AGENDA'S OWN FIVE FIGURES.
 *
 * IT COMPOSES THE DAY'S FOLD RATHER THAN RECOUNTING IT. Check-ins in,
 * verified and the two kinds of problem are the same counts the overview
 * leads with, read from the same {@see DayFigures}; what Today adds is the
 * three the agenda needs and no other surface does — who is on a watch THIS
 * MINUTE, who is on one tomorrow, and the holes inside two days.
 *
 * "ON THE WATCH" IS A CLOCK QUESTION, not a check-in question. Somebody
 * rostered on the night watch at eleven in the morning is not on the watch
 * and has failed at nothing; somebody at post on a day shift is on it
 * whether or not their phone has reported. Folding the two together is the
 * accusation the whole module exists to avoid.
 */
final readonly class TodayFigures
{
    public function __construct(
        public DayFigures $day,
        /** Rostered people whose shift window contains this minute. */
        public int $onTheWatchNow,
        /** Rostered people whose watch has not begun yet today. */
        public int $dueLaterToday,
        /** Watches rostered for tomorrow. */
        public int $onTheWatchTomorrow,
        /** Watches nobody is on, today and tomorrow. */
        public int $holesNextTwoDays,
    ) {
    }

    /**
     * WHAT NEEDS AN ANSWER: a claim the device disagrees with, and a watch
     * nothing arrived for. A hole is not in it — a hole is answered by
     * changing the plan, and it has a figure of its own beside this one.
     */
    public function needingAnAnswer(): int
    {
        return $this->day->flagged + $this->day->noCheckIn;
    }
}
