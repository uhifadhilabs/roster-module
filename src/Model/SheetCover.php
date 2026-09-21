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
 * ONE STATION'S COVER ON ONE DAY — the token under a day column on the
 * station's own head row.
 *
 * IT IS COUNTED PER SHIFT AND STATED AS ONE FIGURE. A station that runs
 * two day watches and two night watches needs four people, and five on
 * the day watch with nobody on the night watch is not four: the count
 * that is stated is what each shift could actually use, which is why
 * {@see $on} is the sum of the covered part of each shift and never the
 * raw head count.
 *
 * A DAY NOTHING IS ASKED OF READS AS A DASH, not as a zero. Null and zero
 * are different facts — "this station names no number" is not "nobody
 * turned up".
 */
final readonly class SheetCover
{
    public function __construct(
        public \DateTimeImmutable $day,
        /** How many of the needed places are covered; null where nothing is asked. */
        public ?int $on,
        /** How many the station says it needs across the shifts it runs; null where it says nothing. */
        public ?int $needed,
        public SheetCoverState $state,
        public bool $isToday = false,
    ) {
    }

    /** What the token prints — "4/4", or a dash where nothing is asked. */
    public function label(): string
    {
        if (null === $this->on || null === $this->needed) {
            return '—';
        }

        return $this->on.'/'.$this->needed;
    }
}
