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

use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\PersonDay;

/**
 * SOMEBODY DUE ON A WATCH, AND HOW THEIR DAY ACTUALLY READS.
 *
 * THE WATCH IS THIS MODULE'S AND THE DAY IS THE AREA'S, and they are kept
 * side by side rather than merged into one verdict: a page has to be able
 * to say "rostered on the night watch, and unverified at 7 km" as two facts,
 * because collapsing them into "absent" is precisely the accusation the
 * whole derivation exists to avoid.
 *
 * A NULL DAY IS "NO CHECK-IN", and it is not the same as not working. The
 * area returns no row for somebody who reported nothing, so the null is
 * what a rostered person with no check-in looks like — and the page says
 * that, rather than marking them missing.
 */
final readonly class RosteredPerson
{
    public function __construct(
        public string $personUuid,
        public string $personName,
        /** The shift key they are due on. */
        public string $shiftKey,
        public string $shiftLabel,
        /** How their day reads, or null where they have reported nothing. */
        public ?PersonDay $day = null,
    ) {
    }

    /**
     * THE STATE TO DRAW. A rostered person the area has no row for is a
     * NO CHECK-IN — which the contract deliberately has no row for, so the
     * null is translated here once rather than at every call site.
     */
    public function state(): DayState
    {
        return null === $this->day ? DayState::NoCheckIn : $this->day->state;
    }

    /** Whether the post can count this person as standing the watch. */
    public function isPresent(): bool
    {
        return $this->state()->countsAsPresent();
    }

    /**
     * A CLAIM THE DEVICE DISAGREES WITH — shown and flagged, never hidden
     * and never quietly corrected. The reason matters and is carried: no
     * fix, no ring and outside the ring are three different things, and one
     * word for all three would be an accusation.
     */
    public function isFlagged(): bool
    {
        return DayState::AtPostUnverified === $this->state();
    }
}
