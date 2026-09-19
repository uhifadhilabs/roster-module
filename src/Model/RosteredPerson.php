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
 * A DAY HOLDS ANY NUMBER OF WATCHES — ruled by the owner. A ranger may
 * check in and out more than once: a morning at the gate, an afternoon on
 * an escort, an evening back at the post. So the area's reading of a day is
 * a LIST, and this object carries the list rather than one interval.
 * Assuming one interval per person per day was the bug that silently
 * dropped the second one — `dayIn()` has always been able to return two
 * rows for the same person, and a reader that keyed them by person kept
 * whichever came last.
 *
 * THE WATCH IS THIS MODULE'S AND THE DAY IS THE AREA'S, and they are kept
 * side by side rather than merged into one verdict: a page has to be able
 * to say "rostered on the night watch, and unverified at 7 km" as two facts,
 * because collapsing them into "absent" is precisely the accusation the
 * whole derivation exists to avoid.
 *
 * AN EMPTY LIST IS "NO CHECK-IN", and it is not the same as not working.
 * The area returns nothing for somebody who reported nothing, and the page
 * says that rather than marking them missing.
 */
final readonly class RosteredPerson
{
    /**
     * @param list<PersonDay> $watches every check-in/check-out the area read for this person on this day, in the order it returned them
     */
    public function __construct(
        public string $personUuid,
        public string $personName,
        /** The shift key they are due on. */
        public string $shiftKey,
        public string $shiftLabel,
        public array $watches = [],
    ) {
    }

    /** How many times they checked in on this day. Zero is a real answer. */
    public function watchCount(): int
    {
        return \count($this->watches);
    }

    /**
     * THE READING A ROW LEADS WITH — the FIRST watch of the day, because a
     * row that led with the latest would make a morning at the gate vanish
     * the moment somebody checked in somewhere else.
     *
     * A surface that wants the whole day iterates {@see $watches}; this is
     * only what a single-line summary shows.
     */
    public function first(): ?PersonDay
    {
        return $this->watches[0] ?? null;
    }

    /**
     * THE STATE TO DRAW for a one-line summary. A rostered person the area
     * has nothing for is a NO CHECK-IN — which the contract deliberately
     * has no row for, so the empty list is translated here once rather than
     * at every call site.
     */
    public function state(): DayState
    {
        $first = $this->first();

        return null === $first ? DayState::NoCheckIn : $first->state;
    }

    /**
     * Whether the post can count this person as standing a watch — TRUE IF
     * ANY of the day's watches counts. Somebody who spent the morning on an
     * escort and the afternoon at the post was present, and a fold that
     * looked only at the last one would say otherwise on a day that ended
     * with a checkout.
     */
    public function isPresent(): bool
    {
        foreach ($this->watches as $watch) {
            if ($watch->state->countsAsPresent()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A CLAIM THE DEVICE DISAGREES WITH — shown and flagged, never hidden
     * and never quietly corrected. TRUE IF ANY watch of the day is
     * unverified: one bad claim in three is still a claim somebody has to
     * look at, and averaging it away is exactly what the flag exists to
     * prevent. The reason is carried per watch, because no fix, no ring and
     * outside the ring are three different things.
     */
    public function isFlagged(): bool
    {
        foreach ($this->watches as $watch) {
            if (DayState::AtPostUnverified === $watch->state) {
                return true;
            }
        }

        return false;
    }

    /** The newest evidence of any kind across the whole day, or null. */
    public function lastSeenAt(): ?\DateTimeImmutable
    {
        $newest = null;
        foreach ($this->watches as $watch) {
            foreach ([$watch->lastPingAt, $watch->occurredAt] as $instant) {
                if (null !== $instant && (null === $newest || $instant > $newest)) {
                    $newest = $instant;
                }
            }
        }

        return $newest;
    }

    /** Every position the day's watches carried, added up. */
    public function pings(): int
    {
        $pings = 0;
        foreach ($this->watches as $watch) {
            $pings += $watch->pings;
        }

        return $pings;
    }
}
