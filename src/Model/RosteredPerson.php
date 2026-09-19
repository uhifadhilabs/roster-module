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
use Uhifadhi\Contracts\Area\PersonWatch;

/**
 * SOMEBODY DUE ON A WATCH, AND HOW THEIR DAY ACTUALLY READS.
 *
 * A DAY HOLDS ANY NUMBER OF WATCHES — ruled by the owner, and the core
 * now says it in the contract's own shape: ONE {@see PersonDay} per person
 * per day, carrying a LIST of {@see PersonWatch}. A ranger checks in at
 * dawn, out at noon, and in again at four; all three are the same day.
 *
 * THE DAY IS THE AREA'S AND THE WATCH IS THIS MODULE'S, and they are kept
 * side by side rather than merged into one verdict: a page has to be able
 * to say "rostered on the night watch, and unverified at 7 km" as two
 * facts, because collapsing them into "absent" is precisely the accusation
 * the whole derivation exists to avoid.
 *
 * NO DAY AT ALL IS "NO CHECK-IN", and it is not the same as not working.
 * The area returns nothing for somebody who reported nothing, and the page
 * says that rather than marking them missing.
 */
final readonly class RosteredPerson
{
    public function __construct(
        public string $personUuid,
        public string $personName,
        /** The shift key they are due on. */
        public string $shiftKey,
        public string $shiftLabel,
        /** The area's whole reading of this person's day, or null where it read nothing. */
        public ?PersonDay $day = null,
    ) {
    }

    /**
     * EVERY WATCH OF THE DAY, in the order they were claimed. A surface
     * draws one row each; an empty list is a day nobody reported.
     *
     * @return list<PersonWatch>
     */
    public function watches(): array
    {
        return $this->day->watches ?? [];
    }

    /** How many times they checked in on this day. Zero is a real answer. */
    public function watchCount(): int
    {
        return \count($this->watches());
    }

    /**
     * THE STATE TO DRAW for a one-line summary, taken from the AREA's own
     * reading of the day rather than re-derived here. The contract states
     * it is the LAST watch's reading — what somebody is doing now, or
     * finished the day doing — which is what a board asks when it colours
     * a name. Folding the list a second way here would give two different
     * answers to one question.
     */
    public function state(): DayState
    {
        return $this->day->state ?? DayState::NoCheckIn;
    }

    /**
     * Whether the post can count this person as standing a watch — TRUE IF
     * ANY of the day's watches counts. Somebody who spent the morning on an
     * escort and the afternoon at the post was present, and a fold that
     * looked only at the last one would say otherwise on a day that ended
     * with a checkout. This is the module's own join and not the day's
     * state, which is why it is not simply {@see state()}.
     */
    public function isPresent(): bool
    {
        foreach ($this->watches() as $watch) {
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
        foreach ($this->watches() as $watch) {
            if (DayState::AtPostUnverified === $watch->state) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE DAY'S TOTAL ON DUTY, in minutes — the area's sum, over the
     * watches that closed. An open watch adds nothing yet and is not
     * nought; {@see isStillOut()} is how a row says "and still working".
     */
    public function minutesOnDuty(): int
    {
        return $this->day?->minutesOnDuty() ?? 0;
    }

    /** Whether any watch of the day is still open. */
    public function isStillOut(): bool
    {
        return $this->day?->hasOpenWatch() ?? false;
    }

    /** The newest evidence of any kind across the whole day, or null. */
    public function lastSeenAt(): ?\DateTimeImmutable
    {
        $newest = $this->day->lastPingAt ?? null;
        foreach ($this->watches() as $watch) {
            foreach ([$watch->lastPingAt, $watch->endedAt, $watch->occurredAt] as $instant) {
                if (null !== $instant && (null === $newest || $instant > $newest)) {
                    $newest = $instant;
                }
            }
        }

        return $newest;
    }

    /** Every position the day carried, as the area counted them. */
    public function pings(): int
    {
        return $this->day->pings ?? 0;
    }
}
